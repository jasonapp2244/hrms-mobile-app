<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2.7 — what the handset said about itself when the punch was made.
 *
 * Attendance drives pay, so the one thing worth knowing about a set of
 * coordinates is whether they were real. Android has reported since API 18
 * whether a fix came from a mock provider, and iOS 15 reports the same for a
 * simulated one — the app has simply never passed it on, so a punch made with a
 * free location-spoofing app was indistinguishable from one made at the door.
 *
 * **Three separate columns, and all three nullable.** Null means *the client
 * said nothing* — a punch from the web portal, from the kiosk, or from a build
 * that predates this — and that is a different fact from `false`, which is the
 * handset actively reporting that it was not mocked. An integrity signal that
 * cannot tell silence from a denial is not one.
 *
 * They are **recorded, never enforced**. The same rule the rest of this system
 * follows for location: office, remote and hybrid staff all clock in from
 * wherever they are, and a false positive that stops somebody being paid is a
 * worse failure than a true positive nobody acted on for a day. A phone running
 * a custom ROM is not fraud; a pattern of mocked fixes is a conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Per-punch, and the strongest of the three: the operating system
            // is stating that *this fix* came from a mock provider.
            $table->boolean('location_mocked')->nullable()->after('longitude');

            // Device-level, and weaker on purpose. A rooted phone can patch the
            // flag above out, which is exactly why this is worth having beside
            // it; it is also an arms race, and a custom ROM trips it honestly.
            $table->boolean('device_rooted')->nullable()->after('location_mocked');
            $table->boolean('device_emulator')->nullable()->after('device_rooted');

            // The board's question is "which punches want a second look", and
            // it is asked across a day of rows.
            $table->index(['company_id', 'work_date', 'location_mocked'], 'attendance_logs_integrity_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_logs_integrity_index');
            $table->dropColumn(['location_mocked', 'device_rooted', 'device_emulator']);
        });
    }
};
