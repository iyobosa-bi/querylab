<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->index()->constrained('users');
            $table->foreignId('technician_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->index()->constrained();
            $table->foreignId('area_id')->index()->constrained();
            $table->string('title');
            $table->text('description');
            $table->string('status', 20); // pending | accepted | in_progress | completed | cancelled
            $table->decimal('budget', 12, 2);
            // Deliberately NOT indexed. Exercise 25 / lab "missing-index" measures the difference.
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE service_requests ADD CONSTRAINT service_requests_status_check
            CHECK (status IN ('pending', 'accepted', 'in_progress', 'completed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
