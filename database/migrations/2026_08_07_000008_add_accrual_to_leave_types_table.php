<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A6.4 — how entitlement arrives over the year.
 *
 * Until now every leave type granted its whole allowance the moment a balance
 * row was created, which is right for a company that front-loads holiday and
 * wrong for one that accrues it. Somebody who joins in November should not be
 * able to book twenty days in December.
 *
 * Two modes, because these are the two that companies actually run:
 *
 *  - `upfront` — the whole allowance, available immediately. The existing
 *    behaviour, and the default, so nothing changes for anyone on upgrade.
 *  - `monthly` — a twelfth per completed month, pro-rated from the hire date.
 *
 * Anything more elaborate (accrual per hour worked, banded by service length)
 * is a rules engine, and that is A2.9's problem rather than this column's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('accrual_mode', 20)->default('upfront')->after('days_per_year');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('accrual_mode');
        });
    }
};
