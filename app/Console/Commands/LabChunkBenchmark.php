<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Support\Lab\QueryMeter;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Sums the budget of every completed request six different ways.
 * Each method runs in its own PHP process so "peak memory" is not polluted
 * by the method before it.
 */
class LabChunkBenchmark extends Command
{
    protected $signature = 'lab:chunk-benchmark
        {--method=all : get, chunk, chunkById, lazy, lazyById, cursor, or all}
        {--size=1000 : chunk size}';

    protected $description = 'Compare get() vs chunk() vs chunkById() vs lazy() vs lazyById() vs cursor() on memory, time and queries';

    private const METHODS = ['get', 'chunk', 'chunkById', 'lazy', 'lazyById', 'cursor'];

    public function handle(QueryMeter $meter): int
    {
        if (! config('lab.enabled') || app()->isProduction()) {
            $this->error('Lab commands are disabled.');

            return self::FAILURE;
        }

        $method = $this->option('method');

        if ($method === 'all') {
            return $this->runAll();
        }

        if (! in_array($method, self::METHODS, true)) {
            $this->error('Unknown method. Use: '.implode(', ', self::METHODS).' or all');

            return self::FAILURE;
        }

        $size = max(1, (int) $this->option('size'));
        $result = $meter->measure(fn () => $this->{'via'.ucfirst($method)}($size));

        $this->line(json_encode([
            'method' => $method,
            'rows' => $result['value']['rows'],
            'sum' => $result['value']['sum'],
            'queries' => $result['queries'],
            'wall_ms' => $result['wall_ms'],
            'peak_memory' => $result['peak_memory'],
        ]));

        return self::SUCCESS;
    }

    private function runAll(): int
    {
        $rows = [];
        foreach (self::METHODS as $method) {
            $this->output->write("  running {$method}... ");
            $process = new Process([PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), base_path('artisan'), 'lab:chunk-benchmark', "--method={$method}", '--size='.$this->option('size')]);
            $process->setTimeout(600)->run();

            if (! $process->isSuccessful()) {
                // Usually "Allowed memory size exhausted" for get(): that IS the lesson.
                $error = trim($process->getErrorOutput() ?: $process->getOutput());
                $this->line('<error>failed</error>');
                $rows[] = [$method, '-', '-', '-', '-', str_contains($error, 'memory size') ? 'OUT OF MEMORY ('.ini_get('memory_limit').' limit)' : 'failed: '.strtok($error, "\n")];

                continue;
            }

            $data = json_decode(trim(collect(explode("\n", trim($process->getOutput())))->last()), true);
            $this->line('done');
            $rows[] = [
                $method,
                number_format($data['rows']),
                number_format($data['sum'], 2),
                $data['queries'],
                number_format($data['wall_ms']).' ms',
                QueryMeter::formatBytes($data['peak_memory']),
            ];
        }

        $this->newLine();
        $this->table(['method', 'rows', 'sum of budgets', 'queries', 'time', 'peak memory'], $rows);
        $this->line('All sums must match. get() holds every model in memory at once; the others stream.');
        $this->line('chunk()/lazy() page with OFFSET, which gets slower the deeper it goes; chunkById()/lazyById() use WHERE id > ?.');
        $this->line('cursor() runs ONE query, but pdo_pgsql still downloads the whole result into libpq memory, which');
        $this->line('PHP does not count. So its "peak memory" looks tiny while the process actually grows. Prefer lazyById().');

        return self::SUCCESS;
    }

    private function query()
    {
        return ServiceRequest::query()->where('status', 'completed');
    }

    private function viaGet(int $size): array
    {
        $rows = 0;
        $sum = 0.0;
        foreach ($this->query()->orderBy('id')->get() as $request) {
            $rows++;
            $sum += (float) $request->budget;
        }

        return compact('rows', 'sum');
    }

    private function viaChunk(int $size): array
    {
        $rows = 0;
        $sum = 0.0;
        $this->query()->orderBy('id')->chunk($size, function ($requests) use (&$rows, &$sum) {
            foreach ($requests as $request) {
                $rows++;
                $sum += (float) $request->budget;
            }
        });

        return compact('rows', 'sum');
    }

    private function viaChunkById(int $size): array
    {
        $rows = 0;
        $sum = 0.0;
        $this->query()->chunkById($size, function ($requests) use (&$rows, &$sum) {
            foreach ($requests as $request) {
                $rows++;
                $sum += (float) $request->budget;
            }
        });

        return compact('rows', 'sum');
    }

    private function viaLazy(int $size): array
    {
        $rows = 0;
        $sum = 0.0;
        foreach ($this->query()->orderBy('id')->lazy($size) as $request) {
            $rows++;
            $sum += (float) $request->budget;
        }

        return compact('rows', 'sum');
    }

    private function viaLazyById(int $size): array
    {
        $rows = 0;
        $sum = 0.0;
        foreach ($this->query()->lazyById($size) as $request) {
            $rows++;
            $sum += (float) $request->budget;
        }

        return compact('rows', 'sum');
    }

    private function viaCursor(int $size): array
    {
        $rows = 0;
        $sum = 0.0;
        foreach ($this->query()->orderBy('id')->cursor() as $request) {
            $rows++;
            $sum += (float) $request->budget;
        }

        return compact('rows', 'sum');
    }
}
