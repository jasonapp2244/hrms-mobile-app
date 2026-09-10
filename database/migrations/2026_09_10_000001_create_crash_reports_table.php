<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B6.5 — crashes the mobile app did not survive.
 *
 * **Deliberately a table on the employer's own server rather than Crashlytics
 * or any other third party.** The privacy policy, the Apple privacy manifest
 * and both store data forms all say the same thing — the only host this app
 * talks to is the employer's server, nothing is shared with anybody, and there
 * is no analytics SDK. A crash reporter that posts stack traces to Google
 * would falsify all three, and it would do it for a class of data that
 * routinely carries fragments of whatever the app was holding at the time.
 *
 * `fingerprint` is what makes the table readable: one hash per distinct place
 * a crash happens, so a hundred handsets hitting one bug read as one row with
 * a count rather than a hundred rows to scroll past.
 *
 * Nothing here is a foreign key that cascades away. A crash from a device that
 * was signed out, or from before anybody signed in at all, is exactly the
 * report worth keeping — the app failing to open is the worst failure it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crash_reports', function (Blueprint $table) {
            $table->id();

            // Both nullable: the app crashes before sign-in too, and that is
            // the crash that matters most.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('platform', 16)->nullable();      // android | ios
            $table->string('app_version', 32)->nullable();
            // One field, not two. Dart hands the app Platform.operatingSystemVersion,
            // which on Android already names the build and the handset and on iOS names
            // the OS build — and reading a model on its own would mean a second plugin
            // and a second line on every store data form for very little.
            $table->string('os_version', 191)->nullable();

            // The exception class, and its message truncated. Messages are
            // capped rather than trusted: an exception is free to carry
            // whatever the failing call was holding, and this table is not the
            // place for it.
            $table->string('exception', 191);
            $table->string('message', 500)->nullable();
            $table->text('stack')->nullable();

            // sha1 of the exception class plus the top frames — one row per
            // distinct place, however many handsets hit it.
            $table->string('fingerprint', 40)->index();

            // When the handset says it happened, which is not when it arrived:
            // a report is written to disk at the moment of the crash and
            // delivered on the next launch, which may be days later on a phone
            // that was left in a locker.
            $table->timestamp('occurred_at')->nullable();

            $table->timestamp('created_at')->nullable()->index();

            // The two ways the screen reads it: newest first for one company,
            // and everything under one fingerprint.
            $table->index(['company_id', 'created_at']);
            $table->index(['fingerprint', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crash_reports');
    }
};
