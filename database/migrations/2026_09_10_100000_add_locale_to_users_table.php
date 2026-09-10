<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What language this person reads (C1.18).
 *
 * Not a setting anybody fills in. The app sends `Accept-Language` with every
 * request and the API middleware writes it here, so the column is a record of
 * what somebody is actually being shown rather than a preference they have to
 * find and maintain in two places.
 *
 * It exists because a notification is not rendered during the request that
 * causes it. HR approving leave in English decides what an employee reads in
 * Spanish, and the worker that formats the message runs with no request at all —
 * so the language has to be a fact about the recipient. This is where
 * `User::preferredLocale()` reads it from.
 *
 * **Null means "nobody has told us"**, and resolves to the default. That is the
 * right state for every account that has only ever used the web dashboard, and
 * for every row that existed before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Guarded: this project has been bitten before by a column MySQL
            // carries and the migrations never declared. See CLAUDE.md, trap 4.
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 5)->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'locale')) {
                $table->dropColumn('locale');
            }
        });
    }
};
