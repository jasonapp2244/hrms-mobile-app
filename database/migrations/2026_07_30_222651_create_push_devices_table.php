<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to send a push notification.
 *
 * Separate from personal_access_tokens on purpose. An access token says "this
 * request is Ann"; a push token says "this handset can be reached". They have
 * different lifetimes — a push token is reissued by the OS without anyone
 * signing in again, and survives a logout unless the app clears it — so tying
 * one to the other would either lose notifications or keep sending them to a
 * phone that has been handed on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The FCM/APNs registration token. Unique because one handset must
            // not appear twice — a duplicate row is the same notification
            // delivered twice — and because re-registration is routine: the OS
            // reissues these on reinstall, on restore, and at its own discretion.
            $table->string('token', 255)->unique();

            $table->enum('platform', ['android', 'ios', 'web']);
            $table->string('device_name')->nullable();
            $table->string('app_version', 30)->nullable();

            // Refreshed on every re-registration, so tokens that have gone quiet
            // for months can be pruned rather than accumulating for ever.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
