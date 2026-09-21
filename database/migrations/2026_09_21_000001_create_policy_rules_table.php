<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2.9 / A6.6 — the conditional rule builder.
 *
 * Everything else on the Policies screen is a value: a grace window, a weekend,
 * a switch. Each answers one question for the whole company and cannot say
 * "except on Fridays", "except in the Croydon depot", or "and tell the manager
 * when it happens". That is what this is for, and it is the last row on the
 * feature list that was neither built nor parked by decision.
 *
 * **Rows rather than code**, because the whole point is that a client can add
 * one without a deployment. The cost is that a rule is data the application has
 * to trust: `conditions` and `actions` are JSON written by an administrator
 * through a form, and both are re-validated against a declared vocabulary
 * (`PolicyRule::FIELDS`, `PolicyRule::ACTIONS`) every time they are read, not
 * only when they are saved. A rule whose field no longer exists — a leave type
 * that was deleted, a column renamed in a later version — is skipped and says
 * so, rather than throwing inside a punch.
 *
 * **Conditions are ANDed, and there is deliberately no OR.** An OR needs
 * grouping, grouping needs parentheses, and parentheses need a builder UI that
 * nobody can use without training. Two rules say the same thing, and each is
 * legible on its own line in the list.
 *
 * **Actions never write to attendance or leave.** They notify, and they record.
 * A rule that could change a punch's status or decide a leave request would put
 * a second, invisible author on rows that payroll and a tribunal both read —
 * and the person reading the row would have no way to tell which of the two
 * wrote it. Telling somebody to look is the useful half of if-this-then-that
 * here; acting for them is the half that costs more than it is worth.
 *
 * `company_id` because a rule belongs to one client, like everything else on
 * this box. `created_by_user_id` is kept and nulled rather than cascaded: who
 * wrote a rule outlives their account, and it is the first thing asked when one
 * turns out to be wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // What an administrator recognises it by in the list. Not derived
            // from the conditions: "Night shift arrivals" says why the rule
            // exists, and "status is late AND office is 3" says what it does.
            $table->string('name', 120);

            // The moment it is evaluated. One of PolicyRule::TRIGGERS — held as
            // a string rather than an enum so adding a trigger is a code
            // release and not a migration on a live table.
            $table->string('trigger', 40);

            // [{field, operator, value}, ...] — all must match.
            $table->json('conditions');

            // [{type, ...}, ...] — every one runs, in order.
            $table->json('actions');

            // Off is a first-class state, not a deletion. A rule that fires too
            // often is switched off while somebody works out why, and the
            // wording that caused it is still there to read.
            $table->boolean('is_active')->default(true);

            // Set every time it matches. The list shows it, because a rule that
            // has never fired is either wrong or unnecessary and the difference
            // matters.
            $table->timestamp('last_fired_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The engine's own query: every active rule for this company on
            // this trigger, which is what runs on each punch.
            $table->index(['company_id', 'trigger', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_rules');
    }
};
