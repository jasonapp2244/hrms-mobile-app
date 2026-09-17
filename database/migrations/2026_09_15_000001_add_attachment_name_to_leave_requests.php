<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4.1 — the name the file was uploaded under.
 *
 * `leave_requests.attachment` has existed since the table was created and has
 * never been written to: there was no way to attach anything, on the web or in
 * the app. It holds the storage path, which is deliberately not the uploaded
 * name — a path built from user-supplied text is a traversal waiting to happen,
 * and two people attaching `scan.pdf` on the same day must not collide.
 *
 * So the original name is kept separately, and it is kept because it is real
 * information for whoever is deciding: "sick-note-14-sep.pdf" and "IMG_2831.jpg"
 * tell an approver what they are about to open, and a hashed filename tells them
 * nothing. It is display text only — every download names the file from this
 * column but reads it from the path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('attachment_name', 255)->nullable()->after('attachment');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('attachment_name');
        });
    }
};
