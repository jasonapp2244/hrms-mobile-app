<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A5.7 — what the shift's break actually means for paid time.
 *
 * `shifts.break_minutes` has always been a number with one hardcoded reading:
 * unpaid, and deducted only as long as the break somebody actually punched.
 * Both halves of that are company policy rather than arithmetic, and neither
 * was expressible.
 *
 * **`break_is_paid`** — the break is worked time. Nothing comes off, live or
 * at the end of the day, and the shift's scheduled hours stop subtracting it
 * too, or scheduled and worked would no longer be comparable and every such
 * shift would manufacture overtime.
 *
 * **`break_is_minimum`** — the shift's break is the *least* that comes off,
 * however short the one that was punched. This is what "an unpaid 30-minute
 * lunch" usually means, and without it the existing rules cut the wrong way in
 * the one case they did not cover: a day with no break punches already has the
 * nominal break deducted, so somebody who punches a ten-minute break is paid
 * for twenty minutes of a break the shift says is unpaid, while the colleague
 * who never touched the button is not. The comment on `overtimeFor` already
 * names that incentive as the wrong one to build into a payroll figure; this
 * is the other half of it.
 *
 * Both default false, which is exactly the behaviour every existing shift has
 * today — nothing about a live company's payroll changes until somebody ticks
 * a box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('break_is_paid')->default(false)->after('break_minutes');
            $table->boolean('break_is_minimum')->default(false)->after('break_is_paid');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['break_is_paid', 'break_is_minimum']);
        });
    }
};
