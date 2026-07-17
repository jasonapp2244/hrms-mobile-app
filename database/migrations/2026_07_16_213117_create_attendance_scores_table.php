<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('period'); // e.g. 2026-07 (monthly) or 2026-W29 (weekly)
            $table->enum('period_type', ['daily', 'weekly', 'monthly'])->default('monthly');
            $table->unsignedInteger('present_days')->default(0);
            $table->unsignedInteger('late_count')->default(0);
            $table->unsignedInteger('absent_count')->default(0);
            $table->unsignedInteger('early_leave_count')->default(0);
            $table->decimal('ontime_pct', 5, 2)->default(0);
            $table->decimal('score', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'period', 'period_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_scores');
    }
};
