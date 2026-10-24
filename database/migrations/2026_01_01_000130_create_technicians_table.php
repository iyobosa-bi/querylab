<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // PostgreSQL does NOT index foreign keys automatically, so we add ->index() ourselves.
            $table->foreignId('area_id')->index()->constrained();
            $table->text('bio')->nullable();
            $table->decimal('hourly_rate', 10, 2);
            $table->unsignedSmallInteger('years_experience');
            $table->boolean('is_available')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('profile_views')->default(0);
            $table->timestamps();
        });

        Schema::create('category_technician', function (Blueprint $table) {
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->index()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('years_in_skill')->default(1);
            $table->timestamps();

            // Composite primary key also serves as the index for "categories of a technician".
            $table->primary(['technician_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_technician');
        Schema::dropIfExists('technicians');
    }
};
