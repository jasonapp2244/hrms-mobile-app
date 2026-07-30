<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One employee, one date, one planned shift.
 *
 * Until now the roster was derived entirely from standing assignments — an
 * employee's shift, or their department's — so every week looked identical and
 * a rotation could not be expressed at all. This is the per-day layer that sits
 * on top: it is consulted first, and where nothing is planned the standing
 * shift still applies, so a company that never opens the planner sees no change.
 *
 * A day off is a planned absence, not a missing row: `is_day_off` says "rostered
 * off" where a null shift_id with is_day_off false would only mean "nothing
 * decided".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Nullable so a rostered day off needs no shift. Nullified rather
            // than cascaded: deleting a shift must not silently erase the plan.
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->boolean('is_day_off')->default(false);
            $table->string('note')->nullable();

            // Drafts are invisible to staff. A planner needs to move people
            // around without everyone watching it change under them.
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One plan per person per day — the planner replaces, never stacks.
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};
