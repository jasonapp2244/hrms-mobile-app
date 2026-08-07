<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A1.7 — two-factor authentication.
 *
 * Three columns, and the separation between the first two is the important
 * part. `two_factor_secret` is written when somebody starts setting 2FA up;
 * `two_factor_confirmed_at` is only written once they have proved they can
 * produce a code from it. Until then the account signs in exactly as before.
 *
 * Without that split, generating a secret would immediately lock out anybody
 * who mistyped it into their authenticator, closed the tab, or scanned it onto
 * a phone they then dropped — which is the single most common way a 2FA rollout
 * goes wrong.
 *
 * Both the secret and the recovery codes are encrypted at the model, so a
 * database dump on its own does not let anybody generate codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
