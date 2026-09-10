<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5.5 — what HR said, and who it was said to.
 *
 * The message itself is delivered as an ordinary notification, so it lands in
 * the bell, the notification centre and FCM with no client change. This table
 * is not that copy: it is HR's own record — the thing you look at to answer
 * "did we tell everyone about the shutdown, and when?" — and the place a draft
 * lives before anybody's phone lights up.
 *
 * Two columns exist because the delivered copies cannot answer for themselves:
 *
 *  - `recipients_count` is written at publish time and never recomputed. Staff
 *    join, leave and move department; asking the audience again next month
 *    would report a number that was never true. What matters afterwards is how
 *    many phones it actually reached.
 *  - `author_label` snapshots the sender's name beside the foreign key, the
 *    same way `activity_logs.actor_label` does, so a deleted account leaves an
 *    unsigned announcement rather than an anonymous one.
 *
 * Nothing here cascades from `departments` or `offices`: an announcement sent
 * to a department that was later merged away is still a true record of what
 * happened, and it keeps the id it was sent to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Nullable on both sides of the pair: the account can go, the name
            // stays.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_label', 120)->nullable();

            $table->string('title', 150);
            $table->text('body');

            // all | department | office. A string rather than an enum column so
            // a fourth audience is a code change and not a migration on a live
            // table.
            $table->string('audience', 20)->default('all');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();

            // Null is a draft. Once this is set the announcement is on people's
            // phones and the model refuses to let it be edited.
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('recipients_count')->nullable();

            $table->timestamps();

            // The register: one company, newest first.
            $table->index(['company_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
