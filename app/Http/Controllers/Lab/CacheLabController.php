<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use App\Repositories\CachedTechnicianRepository;
use App\Repositories\Contracts\TechnicianRepositoryInterface;
use App\Services\DashboardStatsService;
use App\Support\Lab\QueryMeter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CacheLabController extends Controller
{
    public function __construct(private readonly QueryMeter $meter) {}

    // ------------------------------------------------------------------
    //  Dashboard: no cache vs remember vs flexible (service-layer cache)
    // ------------------------------------------------------------------

    public function dashboard(Request $request, DashboardStatsService $stats): View
    {
        $strategy = in_array($request->query('v'), ['none', 'remember', 'flexible'], true) ? $request->query('v') : 'none';

        $result = $this->meter->measure(fn () => $stats->get($strategy));

        return view('lab.cache-dashboard', [
            'strategy' => $strategy,
            'result' => $result,
            'stats' => $result['value'],
            'fresh' => DashboardStatsService::FRESH_SECONDS,
            'stale' => DashboardStatsService::STALE_SECONDS,
        ]);
    }

    public function clearDashboard(Request $request, DashboardStatsService $stats): RedirectResponse
    {
        $stats->clear();

        return redirect()->route('lab.cache.dashboard', ['v' => $request->input('v', 'remember')])
            ->with('status', 'Dashboard cache cleared. The next load recomputes.');
    }

    // ------------------------------------------------------------------
    //  Technician profile: repository decorator + tags + counter
    // ------------------------------------------------------------------

    /** /lab/cache/technicians opens the technician with the most completed jobs. */
    public function busiestTechnician(): RedirectResponse
    {
        $id = Technician::withCount('completedRequests')->orderByDesc('completed_requests_count')->value('id');

        return redirect()->route('lab.cache.technician', $id ?? 1);
    }

    public function technician(int $technician, TechnicianRepositoryInterface $technicians): View
    {
        $result = $this->meter->measure(function () use ($technician, $technicians) {
            $profile = $technicians->findWithProfile($technician);
            abort_if($profile === null, 404);

            return [
                'profile' => $profile,
                'stats' => $technicians->stats($technician),
                'reviews' => $technicians->recentReviews($technician),
                'topInArea' => $technicians->topRatedInArea($profile->area_id, 5),
                // increment() is atomic in Redis: 100 simultaneous visitors give exactly +100.
                // add() first so the counter gets a TTL; increment() on a missing key never expires.
                'viewsToday' => $this->countView($technician),
            ];
        });

        return view('lab.cache-technician', [
            'result' => $result,
            'data' => $result['value'],
            'technicianId' => $technician,
            'previous' => Technician::where('id', '<', $technician)->max('id'),
            'next' => Technician::where('id', '>', $technician)->min('id'),
        ]);
    }

    public function flushTechnician(int $technician): RedirectResponse
    {
        CachedTechnicianRepository::forgetTechnician($technician);

        return back()->with('status', "Flushed tag technician:{$technician}. Its profile, stats and reviews reload from PostgreSQL; the area list stays cached.");
    }

    public function flushAllTechnicians(): RedirectResponse
    {
        CachedTechnicianRepository::forgetAll();

        return back()->with('status', 'Flushed tag "technicians": every technician entry and every list.');
    }

    private function countView(int $technicianId): int
    {
        $key = "technician:{$technicianId}:views:".now()->toDateString();
        Cache::add($key, 0, now()->addDays(2));

        return (int) Cache::increment($key);
    }

    // ------------------------------------------------------------------
    //  OTP: put / pull / add / increment
    // ------------------------------------------------------------------

    private const OTP_TTL = 300;          // code valid for 5 minutes

    private const OTP_MAX_SENDS = 3;      // per phone per 10 minutes

    private const OTP_MAX_ATTEMPTS = 5;   // wrong guesses before the code is burned

    public function otp(): View
    {
        return view('lab.cache-otp');
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        $phone = $request->validate(['phone' => ['required', 'regex:/^0[789][01]\d{8}$/']])['phone'];

        // add() only sets the key if it's missing, which starts a fixed 10-minute window.
        $sendsKey = "otp:sends:{$phone}";
        Cache::add($sendsKey, 0, now()->addMinutes(10));
        if (Cache::increment($sendsKey) > self::OTP_MAX_SENDS) {
            return back()->withInput()->withErrors(['phone' => 'Too many codes requested. Try again in 10 minutes.']);
        }

        $code = (string) random_int(100000, 999999);

        // Store a hash, not the code: anyone who can read Redis still can't log in.
        // put() because we already have the value. The expiry travels with it so it can be restored.
        Cache::put("otp:{$phone}", [
            'hash' => hash('sha256', $code),
            'expires_at' => now()->addSeconds(self::OTP_TTL)->getTimestamp(),
        ], self::OTP_TTL);
        Cache::forget("otp:attempts:{$phone}");

        // In a real app this goes out by SMS. The lab shows it so you can test.
        return back()->withInput()->with('status', "Code for {$phone}: {$code} (valid 5 minutes)");
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'regex:/^0[789][01]\d{8}$/'],
            'code' => ['required', 'digits:6'],
        ]);
        $phone = $data['phone'];

        $attemptsKey = "otp:attempts:{$phone}";
        Cache::add($attemptsKey, 0, self::OTP_TTL);
        $attempts = Cache::increment($attemptsKey);

        // pull() = get + delete. Two requests with the right code can't both succeed.
        $stored = Cache::pull("otp:{$phone}");

        if (! $stored || $stored['expires_at'] <= now()->getTimestamp()) {
            return back()->withInput()->withErrors(['code' => 'Code expired or not requested.']);
        }

        if (! hash_equals($stored['hash'], hash('sha256', $data['code']))) {
            if ($attempts < self::OTP_MAX_ATTEMPTS) {
                // Wrong guess: put the code back with its REMAINING lifetime, not a fresh 5 minutes.
                Cache::put("otp:{$phone}", $stored, $stored['expires_at'] - now()->getTimestamp());

                return back()->withInput()->withErrors(['code' => 'Wrong code. '.(self::OTP_MAX_ATTEMPTS - $attempts).' attempts left.']);
            }

            return back()->withInput()->withErrors(['code' => 'Too many wrong attempts. Request a new code.']);
        }

        Cache::forget($attemptsKey);

        return back()->with('status', "Verified {$phone}. The code was pulled from the cache, so it can't be used again.");
    }
}
