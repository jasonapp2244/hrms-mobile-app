<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trail behind every attendance record.
 *
 * Attendance decides what people are paid and whether they kept their hours, so
 * a punch that can be quietly edited is worth very little in a dispute. Two
 * things make it defensible: punches are append-only (enforced on the model),
 * and every write leaves a row here saying who caused it and how.
 *
 * Nothing corrects a punch today — HR correction is a later feature — but the
 * trail has to exist before the first correction, not after it. A log that
 * starts recording halfway through cannot answer questions about the period it
 * missed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_audit_events', function (Blueprint $table) {
            $table->id();

            // Declared here, not added later. Two tables in this codebase already
            // carried an undeclared company_id that broke inserts on MySQL while
            // the SQLite test schema stayed happy — that is not repeated.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Nullable so the trail can outlive the row it describes. Nothing
            // deletes a punch today, but an audit record that vanishes with its
            // subject would defeat the point of keeping one.
            $table->foreignId('attendance_log_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // created — a new punch. corrected — an existing one amended.
            // voided — struck out without deleting the row.
            $table->string('event', 20);

            // Null means the system acted: the nightly close, a seeder, an import.
            // That is a real answer, not missing data, so it is not defaulted away.
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // The actor's name at the time, kept alongside the id. People leave and
            // their accounts get removed; a trail that reads "user 14" a year later
            // answers nothing, and the foreign key would already be null by then.
            $table->string('actor_label', 150)->nullable();

            $table->string('source', 30)->nullable();

            // Required for a correction, meaningless for a creation.
            $table->text('reason')->nullable();

            // The punch as it was and as it became. Null `before` on a creation:
            // there was nothing there.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip_address', 45)->nullable();

            // created_at only. An audit row that could be updated would need its
            // own audit trail.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['employee_id', 'created_at']);
            $table->index('attendance_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_events');
    }
};
