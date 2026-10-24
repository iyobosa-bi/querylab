<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('customer')->index(); // customer | technician | admin
            $table->string('phone', 20)->nullable()->unique();
            $table->foreignId('area_id')->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropUnique(['phone']);
            $table->dropColumn(['role', 'phone']);
        });
    }
};
