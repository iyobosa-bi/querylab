<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One "technician tapping Accept". lab:race starts several of these at the same moment.
 */
class LabAcceptJob extends Command
{
    protected $signature = 'lab:accept-job {request} {technician} {--mode=nolock} {--start-at=0}';

    protected $description = 'Internal: used by lab:race';

    protected $hidden = true;

    public function handle(): int
    {
        if (! config('lab.enabled') || app()->isProduction()) {
            return self::FAILURE;
        }

        $requestId = (int) $this->argument('request');
        $technicianId = (int) $this->argument('technician');

        // Wait for the shared start time so all workers really do run at once.
        $startAt = (float) $this->option('start-at');
        if ($startAt > microtime(true)) {
            usleep((int) (($startAt - microtime(true)) * 1e6));
        }

        $outcome = match ($this->option('mode')) {
            'lock' => $this->withLock($requestId, $technicianId),
            'atomic' => $this->atomic($requestId, $technicianId),
            default => $this->checkThenWrite($requestId, $technicianId),
        };

        $this->line(json_encode(['technician' => $technicianId, 'outcome' => $outcome, 'at' => microtime(true)]));

        return self::SUCCESS;
    }

    /** The bug: read, think, write. Everyone reads "no technician" before anyone writes. */
    private function checkThenWrite(int $requestId, int $technicianId): string
    {
        $job = ServiceRequest::find($requestId);
        if ($job->technician_id !== null) {
            return 'taken';
        }

        usleep(200_000); // "checking the technician's schedule" — widens the race window

        DB::table('service_requests')->where('id', $requestId)->update([
            'technician_id' => $technicianId, 'status' => 'accepted', 'accepted_at' => now(),
        ]);

        return 'ACCEPTED';
    }

    /** Only one process holds the lock at a time; the others wait up to 5s, then re-check. */
    private function withLock(int $requestId, int $technicianId): string
    {
        try {
            return Cache::lock("service-request:{$requestId}:accept", 10)->block(5, function () use ($requestId, $technicianId) {
                return $this->checkThenWrite($requestId, $technicianId);
            });
        } catch (LockTimeoutException) {
            return 'timeout';
        }
    }

    /** No lock: the WHERE technician_id IS NULL makes the database pick exactly one winner. */
    private function atomic(int $requestId, int $technicianId): string
    {
        usleep(200_000);

        $updated = DB::table('service_requests')
            ->where('id', $requestId)
            ->whereNull('technician_id')
            ->update(['technician_id' => $technicianId, 'status' => 'accepted', 'accepted_at' => now()]);

        return $updated === 1 ? 'ACCEPTED' : 'taken';
    }
}
