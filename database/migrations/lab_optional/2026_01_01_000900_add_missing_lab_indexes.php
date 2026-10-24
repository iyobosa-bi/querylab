<?php

/*
 * NOT run by a normal `php artisan migrate`, because it lives in a subfolder.
 *
 * Measure first (lab "missing-index" + Exercise 25/27), then run:
 *     php artisan migrate --path=database/migrations/lab_optional
 * and measure again. Roll back to repeat the experiment:
 *     php artisan migrate:rollback --path=database/migrations/lab_optional
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->index('scheduled_at');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->index('technician_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['technician_id']);
        });
    }
};
