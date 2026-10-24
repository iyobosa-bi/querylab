<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Starts several "technicians" accepting the same pending job at the same
 * moment, as separate PHP processes, then shows who thinks they won.
 * The job is put back exactly as it was afterwards.
 */
class LabRace extends Command
{
    protected $signature = 'lab:race
        {--mode=nolock : nolock, lock or atomic}
        {--workers=5 : how many technicians accept at once}';

    protected $description = 'Race condition demo: several technicians accept one job at the same time';

    public function handle(): int
    {
        if (! config('lab.enabled') || app()->isProduction()) {
            $this->error('Lab commands are disabled.');

            return self::FAILURE;
        }

        $mode = $this->option('mode');
        if (! in_array($mode, ['nolock', 'lock', 'atomic'], true)) {
            $this->error('Mode must be nolock, lock or atomic.');

            return self::FAILURE;
        }

        $job = ServiceRequest::where('status', 'pending')->whereNull('technician_id')->latest('id')->first();
        if (! $job) {
            $this->error('No pending, unassigned request found. Re-seed the database.');

            return self::FAILURE;
        }

        $original = DB::table('service_requests')->where('id', $job->id)->first(['technician_id', 'status', 'accepted_at', 'updated_at']);
        $technicianIds = DB::table('technicians')->where('is_verified', true)->orderBy('id')
            ->limit(max(2, (int) $this->option('workers')))->pluck('id');

        $this->info("Job #{$job->id} \"{$job->title}\" is pending. {$technicianIds->count()} technicians tap Accept at the same moment. Mode: {$mode}");

        // Give every process time to boot Laravel, then all start at this exact time.
        $startAt = microtime(true) + 2.0;
        $processes = $technicianIds->map(function ($technicianId) use ($job, $mode, $startAt) {
            $process = new Process([
                PHP_BINARY, base_path('artisan'), 'lab:accept-job', $job->id, $technicianId,
                "--mode={$mode}", "--start-at={$startAt}",
            ]);
            $process->setTimeout(30)->start();

            return $process;
        });

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $line = collect(explode("\n", trim($process->getOutput())))->last();
            $data = json_decode((string) $line, true);
            if (! $data) {
                $this->error(trim($process->getErrorOutput() ?: $process->getOutput()));

                continue;
            }
            $results[] = $data;
        }

        usort($results, fn ($a, $b) => $a['at'] <=> $b['at']);
        $finalTechnician = DB::table('service_requests')->where('id', $job->id)->value('technician_id');
        $winners = collect($results)->where('outcome', 'ACCEPTED')->count();

        $this->table(['technician', 'told', 'finished at'], collect($results)->map(fn ($r) => [
            $r['technician'], $r['outcome'], sprintf('+%.0f ms', ($r['at'] - $startAt) * 1000),
        ])->all());

        $this->line("Job is now assigned to technician #{$finalTechnician}.");
        if ($winners > 1) {
            $this->error("{$winners} technicians were told they got the job. Only one did. This is the race condition.");
        } else {
            $this->info('Exactly one technician was told they got the job.');
        }

        // Put the job back so the demo can be repeated.
        DB::table('service_requests')->where('id', $job->id)->update((array) $original);
        $this->line('(Job restored to pending.)');

        return self::SUCCESS;
    }
}
