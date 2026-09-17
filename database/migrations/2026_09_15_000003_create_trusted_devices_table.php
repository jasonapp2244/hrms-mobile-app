<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1.6 — one account, one handset.
 *
 * The gap this closes is the oldest one in attendance: somebody hands a
 * colleague their password and the colleague clocks them in from the car park.
 * Geofencing does not touch it — the colleague is *at* the office. Mock-location
 * detection does not touch it either; nothing is being spoofed.
 *
 * **A table of its own, not a column on `push_devices`.** Those rows are
 * delivery addresses for notifications: the OS rotates a push token on its own
 * schedule, several can exist for one handset, and they are dropped when
 * notifications are declined. Identity has to be stable across exactly the
 * events a push token is not. Hanging a security control off a value that
 * rotates would be a control that fails open at a moment nobody chose.
 *
 * `device_id` is a UUID **the app generates once and keeps in the keystore**,
 * not a hardware identifier. Two reasons, and the privacy one is not the
 * stronger:
 *
 *  - A hardware id (`ANDROID_ID`, `identifierForVendor`) is a persistent
 *    cross-app handle on a person, collected for an HR system that has no need
 *    of one, and it drags Play Store data-safety declarations along with it.
 *  - It would not help. The threat is a *second* person signing in, and their
 *    handset has a different id under either scheme. Clearing app data gets the
 *    borrower a fresh id, which is still not the bound one.
 *
 * The cost is honest and worth stating: an employee who wipes the app, or gets
 * a new phone, needs HR to release the binding. That is the same call HR
 * already takes for a forgotten password, and `released_at` keeps the history
 * rather than deleting the row — who was trusted, and when that stopped, is
 * exactly what somebody asks after a dispute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The app's own UUID for this handset.
            $table->string('device_id', 100);

            // For the person reading the list — "Ann's Pixel", not a UUID.
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 20)->nullable();

            $table->timestamp('trusted_at');
            $table->timestamp('last_seen_at')->nullable();

            // Set rather than deleted, so the trail survives the release.
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per handset per account. The unique key includes
            // `released_at` deliberately **not** at all: a released row must not
            // block the same handset being trusted again, so re-trusting writes
            // a new row and the old one stays as history.
            $table->index(['user_id', 'released_at']);
            $table->index(['company_id', 'released_at']);
            $table->index(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
