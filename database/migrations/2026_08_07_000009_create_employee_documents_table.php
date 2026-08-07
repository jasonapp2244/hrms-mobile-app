<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3.8 — the document vault, and the expiry that is the whole point of it.
 *
 * Storing contracts and ID scans is the easy half. The half that earns its keep
 * is `expires_on`: a work permit or a right-to-work document that lapsed
 * unnoticed is a compliance failure, and the only reason it goes unnoticed is
 * that nobody is watching a folder.
 *
 * The uploaded file lives on the private disk, never in `public/`. These are
 * passports and contracts; a guessable URL that serves them without a session
 * is the one mistake in this feature that actually matters.
 *
 * `original_name` is kept because the stored name is a random hash — necessary
 * so an upload cannot overwrite another, and useless to a human. The download
 * hands back the name they uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('title', 200);

            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);

            // Null for a document that does not expire — a signed contract, a
            // qualification certificate. Only the ones with a date are chased.
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->string('notes', 500)->nullable();

            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // When the expiry warning was last sent, so the nightly job nags
            // once per document rather than every night until somebody acts.
            $table->timestamp('expiry_notified_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'type']);
            // The nightly sweep: everything for one company with a date on it.
            $table->index(['company_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
