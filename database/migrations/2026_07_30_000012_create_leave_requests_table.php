<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Restrict, not cascade: deleting a leave type must not silently erase
            // the history of leave already taken under it.
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            // Chargeable days after weekends and holidays are excluded. Computed on
            // submission and stored, so a later calendar change cannot retroactively
            // alter an approved request.
            $table->decimal('days', 5, 1)->default(0);

            $table->boolean('is_half_day')->default(false);
            $table->enum('half_day_period', ['first_half', 'second_half'])->nullable();

            $table->text('reason')->nullable();
            $table->string('attachment')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])
                ->default('pending');

            // Decision trail. Nullified rather than cascaded so the request survives
            // the approver's account being deleted.
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
