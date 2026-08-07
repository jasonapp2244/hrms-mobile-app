<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A1.8 — who signed in, who tried and failed, and who changed what.
 *
 * Attendance already has its own immutable trail, but it only covers punches.
 * The questions this table answers are the ones asked after something has gone
 * wrong and nobody will own up to it: who was in the system on Tuesday night,
 * whose password was being guessed at, and who gave that account the admin role.
 *
 * Deliberately not a foreign-key-only design. `actor_label` holds the name as it
 * was at the time and `user_id` is nulled rather than cascaded when an account
 * is deleted, because "this account was deleted" is precisely the entry somebody
 * will come looking for, and a cascade would take it with them. A failed login
 * carries no user_id at all — the whole point is that the credentials matched
 * nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: a failed sign-in happens before anybody is identified,
            // so there is no company to attribute it to.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The actor as they were, so the trail still reads after a rename or
            // a deletion. For a failed login this is the address that was tried.
            $table->string('actor_label')->nullable();

            $table->string('event', 40);
            $table->string('description', 500)->nullable();

            // What was acted on, when the event is about something — a role, an
            // employee, a settings key. Polymorphic and unconstrained: the
            // subject may well be gone by the time anybody reads this.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // No updated_at. A row that can be revised is not a record of
            // anything, and the model refuses to revise one.
            $table->timestamp('created_at')->nullable()->index();

            // The two ways the screen is read: everything for one company newest
            // first, and everything one person did.
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
