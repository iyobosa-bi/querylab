<?php

namespace App\Support\Lab;

use Closure;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Measures one block of code: queries, SQL time, wall time, peak memory,
 * models hydrated and cache hits/misses. The lab pages show these numbers
 * next to Debugbar's so you can compare, and the artisan commands use them
 * where Debugbar isn't available.
 */
final class QueryMeter
{
    private bool $recording = false;

    private int $queries = 0;

    private float $sqlMs = 0.0;

    private int $models = 0;

    /** @var array<string, int> */
    private array $cache = ['hit' => 0, 'miss' => 0, 'write' => 0, 'forget' => 0];

    /** @var array<string, array{count:int, ms:float}> */
    private array $statements = [];

    public function recordQuery(QueryExecuted $event): void
    {
        if (! $this->recording) {
            return;
        }

        $this->queries++;
        $this->sqlMs += $event->time;
        $this->statements[$event->sql] ??= ['count' => 0, 'ms' => 0.0];
        $this->statements[$event->sql]['count']++;
        $this->statements[$event->sql]['ms'] += $event->time;
    }

    public function recordModel(): void
    {
        if ($this->recording) {
            $this->models++;
        }
    }

    public function recordCache(string $type): void
    {
        if ($this->recording) {
            $this->cache[$type]++;
        }
    }

    /**
     * @return array{value:mixed, queries:int, sql_ms:float, wall_ms:float, peak_memory:int,
     *               models:int, cache:array<string,int>, statements:array<string,array{count:int,ms:float}>}
     */
    public function measure(Closure $callback): array
    {
        $this->queries = 0;
        $this->sqlMs = 0.0;
        $this->models = 0;
        $this->cache = array_map(fn () => 0, $this->cache);
        $this->statements = [];

        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage();
        $started = hrtime(true);
        $this->recording = true;

        try {
            $value = $callback();
        } finally {
            $this->recording = false;
        }

        $wallMs = (hrtime(true) - $started) / 1e6;
        $peak = max(0, memory_get_peak_usage() - $memoryBefore);

        $statements = $this->statements;
        uasort($statements, fn ($a, $b) => $b['count'] <=> $a['count'] ?: $b['ms'] <=> $a['ms']);

        return [
            'value' => $value,
            'queries' => $this->queries,
            'sql_ms' => round($this->sqlMs, 2),
            'wall_ms' => round($wallMs, 2),
            'peak_memory' => $peak,
            'models' => $this->models,
            'cache' => $this->cache,
            'statements' => $statements,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 0).' KB';
    }
}
