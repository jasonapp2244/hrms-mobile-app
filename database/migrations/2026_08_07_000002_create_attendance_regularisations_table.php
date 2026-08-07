<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4.13 — an employee's request to have their attendance record corrected.
 *
 * A4.12 gave HR the power to key in and strike out punches. This is the other
 * half: the employee who noticed the mistake is almost never the person allowed
 * to fix it, and without a route for them to raise it the correction happens
 * over a chat message and leaves no trace of who asked or why.
 *
 * One shape covers both real cases. A missing punch — forgot to check out —
 * leaves attendance_log_id null and simply asks for one to be recorded. A wrong
 * punch — "this says 09:40, I was here at 09:00" — points at the offending row,
 * which is voided on approval before the corrected one is written.
 *
 * Nothing here edits attendance directly. Approval goes through the same void
 * and manual-entry paths HR uses by hand, so every resulting punch carries the
 * usual audit trail and a struck-out row is still a struck-out row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_regularisations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // The punch being challenged, if any. Null means "there should be a
            // punch here and there isn't". Nulled rather than cascaded: if the
            // referenced row somehow goes, the request and its decision are
            // still the record of what was asked for.
            $table->foreignId('attendance_log_id')->nullable()
                ->constrained('attendance_logs')->nullOnDelete();

            $table->date('work_date');
            $table->enum('type', ['in', 'out']);

            // The time the employee says is correct, in the company's timezone,
            // stored the same way scanned_at is so the two are comparable.
            $table->dateTime('requested_at');

            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();

            // Why. Not nullable — a request with no stated cause gives the
            // approver nothing to decide on.
            $table->string('reason', 500);

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])
                ->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('decided_by_label')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            // The punch written when this was approved, so the request and the
            // record it produced can be read back against each other.
            $table->foreignId('created_log_id')->nullable()
                ->constrained('attendance_logs')->nullOnDelete();

            $table->timestamps();

            // The employee's own list, and the approver's pending queue.
            $table->index(['employee_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularisations');
    }
};
