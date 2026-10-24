<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service-layer caching example: this result combines several tables and
 * business rules, so it is cached here rather than in one repository.
 */
class DashboardStatsService
{
    public const KEY = 'dashboard:stats';

    /** Short on purpose so you can watch the fresh → stale → refresh cycle in the lab. */
    public const FRESH_SECONDS = 20;

    public const STALE_SECONDS = 60;

    public function get(string $strategy): array
    {
        return match ($strategy) {
            'remember' => Cache::remember(self::KEY.':remember', self::STALE_SECONDS, fn () => $this->compute()),
            // flexible: fresh for 20s; between 20s and 60s the old value is served
            // instantly and refreshed AFTER the response is sent; after 60s it recomputes.
            'flexible' => Cache::flexible(self::KEY.':flexible', [self::FRESH_SECONDS, self::STALE_SECONDS], fn () => $this->compute()),
            default => $this->compute(),
        };
    }

    public function clear(): void
    {
        Cache::forget(self::KEY.':remember');
        Cache::forget(self::KEY.':flexible');
        // flexible() stores its "created at" timestamp under a separate key.
        Cache::forget('illuminate:cache:flexible:created:'.self::KEY.':flexible');
    }

    public function compute(): array
    {
        $today = CarbonImmutable::parse(config('lab.anchor_date'));

        $byStatus = DB::table('service_requests')
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS pending', ['pending'])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?)) AS active', ['accepted', 'in_progress'])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS completed', ['completed'])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS cancelled', ['cancelled'])
            ->first();

        $revenue30 = DB::table('payments')
            ->where('status', 'success')
            ->whereBetween('paid_at', [$today->subDays(30), $today])
            ->sum('amount');

        $topTechnicians = DB::table('service_requests as sr')
            ->join('technicians as t', 't.id', '=', 'sr.technician_id')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('sr.status', 'completed')
            ->whereBetween('sr.completed_at', [$today->subDays(90), $today])
            ->groupBy('t.id', 'u.name')
            ->orderByDesc('jobs')
            ->limit(5)
            ->get(['t.id', 'u.name', DB::raw('COUNT(*) AS jobs')]);

        $avgRating90 = DB::table('reviews')
            ->whereBetween('created_at', [$today->subDays(90), $today])
            ->avg('rating');

        $activeCustomers30 = DB::table('service_requests')
            ->whereBetween('created_at', [$today->subDays(30), $today])
            ->distinct()
            ->count('customer_id');

        return [
            'computed_at' => now()->toDateTimeString(),
            'by_status' => (array) $byStatus,
            'revenue_30_days' => (float) $revenue30,
            'avg_rating_90_days' => $avgRating90 === null ? null : round((float) $avgRating90, 2),
            'active_customers_30_days' => $activeCustomers30,
            'top_technicians_90_days' => $topTechnicians->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
