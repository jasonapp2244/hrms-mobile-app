<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A8.5 — which dashboard panels a person wants to see.
 *
 * Per user, not per role. Two administrators do different jobs: one lives in
 * the security trail, the other only ever looks at who is in today, and a
 * role-wide setting would force them onto the same screen.
 *
 * Null means "not chosen yet" and is deliberately distinct from an empty list.
 * Unset falls back to the sensible default for the person's role (A8.4); an
 * empty array means they have turned everything off, which is their business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dashboard_widgets')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_widgets');
        });
    }
};
