<?php

namespace Database\Seeders;

use Database\Seeders\Lab\LabDataGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the whole lab dataset with bulk inserts (~1,000 rows per INSERT).
 *
 *   php artisan db:seed                    # uses LAB_SIZE from .env (default: small)
 *   LAB_SIZE=large php artisan migrate:fresh --seed
 *
 * Re-running is safe: it truncates the lab tables first.
 */
class LabSeeder extends Seeder
{
    private const TABLES = [
        'payments', 'reviews', 'quotes', 'service_requests', 'category_technician',
        'technicians', 'users', 'categories', 'areas', 'settings',
    ];

    public function run(): void
    {
        $size = config('lab.size');
        $started = microtime(true);

        // Seeding 500k rows with query logging would use a lot of memory. Two things log queries:
        //  - the query log (off by default outside tests, turned off here to be sure)
        //  - with APP_DEBUG=true, Laravel's error page keeps the first 100 queries WITH their
        //    bindings. A 1,000-row insert has ~15,000 bindings, so that alone costs ~120 MB.
        // Detaching the event dispatcher from the connection while seeding avoids both.
        DB::disableQueryLog();
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();

        // TRUNCATE ... CASCADE is PostgreSQL syntax; RESTART IDENTITY resets the id sequences.
        DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');

        $generator = new LabDataGenerator(
            insert: fn (string $table, array $rows) => DB::table($table)->insert($rows),
            size: $size,
            seed: config('lab.seed'),
            anchorDate: config('lab.anchor_date'),
            passwordHash: Hash::make('password'),
        );

        try {
            $counts = DB::transaction(fn () => $generator->run());
        } finally {
            if ($events) {
                $connection->setEventDispatcher($events);
            }
        }

        // We inserted explicit ids, so move each sequence past the highest id.
        // Without this, the next Model::create() fails with a duplicate key error.
        foreach (self::TABLES as $table) {
            if ($table === 'category_technician') {
                continue;
            }
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))");
        }

        // Refresh planner statistics so EXPLAIN shows realistic plans straight away.
        DB::statement('ANALYZE');

        // Anything cached from a previous dataset is now wrong.
        Cache::flush();

        $this->command?->info(sprintf('Lab dataset "%s" seeded in %.1fs', $size, microtime(true) - $started));
        $this->command?->table(['table', 'rows'], collect($counts)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all());
    }
}
