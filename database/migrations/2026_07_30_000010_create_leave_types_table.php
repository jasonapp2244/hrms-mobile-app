<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');                 // Annual Leave, Sick Leave, Unpaid…
            $table->string('code', 30)->nullable(); // AL, SL, UL
            // Days granted per full year. Decimal so half-day entitlements work.
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('allow_half_day')->default(true);
            // Null = unlimited carry-forward, 0 = none, N = cap in days.
            $table->decimal('carry_forward_max', 5, 1)->nullable();
            $table->string('color', 20)->default('#F26522');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
