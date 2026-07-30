<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notification table.
 *
 * Guarded on hasTable: the development database already carried this table,
 * created outside any migration. Its columns match this definition exactly, so
 * there is nothing to reconcile — skipping is correct, and dropping a
 * compatible table to recreate it identically would only risk the data in it.
 *
 * The extra (notifiable, read_at) index that database also has is a fair
 * improvement — the unread count queries exactly those three columns — so it is
 * included here rather than left as a difference between installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Every bell render counts unread rows for one user.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
