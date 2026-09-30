<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR check-in (A4.21) — the office screen, the codes it shows, and the one-time
 * sign-in code in the welcome email.
 *
 * Client requirement of 2026-09-30, and the reverse of the one on 2026-07-21
 * that removed the earlier kiosk. The shape differs from that one in the two
 * ways that matter:
 *
 *  - **The employee's own phone is the scanner.** Identity comes from the
 *    signed-in app, the code proves they are standing at the screen, and the
 *    server decides in or out exactly as it does for the button.
 *  - **A code is good for one scan.** The old kiosk rotated on a clock, so a
 *    code seen by one person was usable by anybody for twenty seconds. Each row
 *    here is consumed by exactly one punch, and expires unused within seconds
 *    besides — a photograph of the screen is worthless by the time it reaches
 *    anybody.
 *
 * Hashes rather than values: the screen is sent the plain token once, when the
 * row is made, and nothing can read one back out of the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_displays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();

            // "Front desk tablet" — for whoever reads the list.
            $table->string('name', 100);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();

            // Set rather than deleted, so a punch keeps the screen it came from.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'revoked_at']);
        });

        Schema::create('attendance_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_display_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');

            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('attendance_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();

            $table->timestamps();

            $table->index(['qr_display_id', 'consumed_at']);
        });

        // The one-time code in the welcome email that signs a phone in. Never a
        // punch: a code that sits in an inbox can be forwarded, and one that
        // could clock somebody in would be the buddy-punch the office screen
        // exists to stop.
        Schema::create('activation_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_codes');
        Schema::dropIfExists('attendance_qr_tokens');
        Schema::dropIfExists('qr_displays');
    }
};
