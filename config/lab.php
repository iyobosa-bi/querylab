<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dataset size
    |--------------------------------------------------------------------------
    | "small" (~600 service requests) is for checking query results by eye.
    | "large" (100,000 service requests) is for performance, EXPLAIN and caching.
    */
    'size' => env('LAB_SIZE', 'small'),

    /*
    |--------------------------------------------------------------------------
    | Random seed
    |--------------------------------------------------------------------------
    | The seeder is deterministic: the same seed and size always produce exactly
    | the same rows, so answers can be compared against CHECK_VALUES.md.
    */
    'seed' => (int) env('LAB_SEED', 2026),

    /*
    |--------------------------------------------------------------------------
    | "Today" for the dataset
    |--------------------------------------------------------------------------
    | Seeded data runs from 2024-09-01 to this date. Exercises that say "last
    | 30 days" or "this month" mean relative to this date, not now(), so your
    | answers never change as real time passes.
    */
    'anchor_date' => env('LAB_ANCHOR_DATE', '2026-08-31 23:59:59'),

    /*
    | Lab pages and commands only run when this is true (and never in production).
    */
    'enabled' => (bool) env('LAB_ENABLED', true),

];
