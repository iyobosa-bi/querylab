<?php

namespace App\Console\Commands;

use App\Models\Quote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The classic chunk() bug: updating the column you filter on while chunking
 * silently skips rows. Everything runs in a transaction that is rolled back.
 */
class LabChunkBug extends Command
{
    protected $signature = 'lab:chunk-bug {--size= : chunk size (default: 100, or smaller on the small dataset)}';

    protected $description = 'Show chunk() skipping rows when you update the filtered column, and chunkById() fixing it';

    public function handle(): int
    {
        if (! config('lab.enabled') || app()->isProduction()) {
            $this->error('Lab commands are disabled.');

            return self::FAILURE;
        }

        $pending = Quote::where('status', 'pending')->count();
        // The bug needs several chunks to show, so use smaller chunks on the small dataset.
        $size = $this->option('size') ? max(1, (int) $this->option('size')) : min(100, max(2, intdiv($pending, 5)));
        $this->info("Task: reject every pending quote. There are {$pending} pending quotes. Chunk size {$size}.");
        $this->newLine();

        foreach (['chunk', 'chunkById'] as $method) {
            DB::beginTransaction();

            try {
                $processed = 0;
                $batches = 0;
                Quote::where('status', 'pending')->orderBy('id')->{$method}($size, function ($quotes) use (&$processed, &$batches) {
                    $batches++;
                    foreach ($quotes as $quote) {
                        $quote->status = 'rejected';
                        $quote->save();
                        $processed++;
                    }
                });

                $left = Quote::where('status', 'pending')->count();
                $this->line(sprintf(
                    '  %-10s processed %5d rows in %3d batches, %5d still pending  %s',
                    $method.'()', $processed, $batches, $left,
                    $left > 0 ? '<- rows were SKIPPED' : '<- correct',
                ));
            } finally {
                DB::rollBack();
            }
        }

        $this->newLine();
        $this->line('Why: chunk() pages with OFFSET. After batch 1 is rejected, those rows no longer match');
        $this->line("WHERE status = 'pending', so the next OFFSET {$size} jumps over {$size} rows that were never touched.");
        $this->line('chunkById() pages with WHERE id > {last id}, which is not affected by the update.');
        $this->line('Even better here: a single Quote::where(\'status\', \'pending\')->update([...]).');
        $this->line('(All changes were rolled back.)');

        return self::SUCCESS;
    }
}
