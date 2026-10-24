<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            // Deliberately NOT indexed: PostgreSQL won't add one for the foreign key.
            // Exercise 27 asks you to find this with EXPLAIN and fix it.
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending | accepted | rejected
            $table->timestamps();

            // One quote per technician per request. Its leading column also indexes service_request_id.
            $table->unique(['service_request_id', 'technician_id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->index()->constrained('users');
            $table->foreignId('technician_id')->index()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->index()->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20); // card | transfer | cash | wallet  (deliberately not indexed)
            $table->string('status', 20); // success | failed | refunded
            $table->string('reference')->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('quotes');
    }
};
