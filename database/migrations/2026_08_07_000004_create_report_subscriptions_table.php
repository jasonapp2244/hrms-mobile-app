<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A7.12 — standing orders for report email delivery.
 *
 * The reports themselves already exist and are already exportable; what was
 * missing is that somebody has to remember to go and pull them. A payroll
 * export that arrives on the first of the month is worth more than one that has
 * to be fetched, because the fetching is what stops happening in a busy week.
 *
 * A row is a standing order: this report, this often, to these people. It is
 * deliberately not tied to a user account — the finance mailbox that wants the
 * payroll hours may well have no login here, and requiring one would mean
 * creating dummy accounts just to receive a PDF.
 *
 * `last_sent_at` is the whole of the duplicate guard. The command runs hourly
 * and each company decides in its own timezone whether its send hour has come,
 * so without it a company that spans a DST change would get the same month
 * twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Which report — the same keys ReportController dispatches on, so a
            // subscription names a report that demonstrably exists.
            $table->string('report_type', 32);

            $table->enum('frequency', ['daily', 'weekly', 'monthly']);

            // pdf reads; excel totals. Which one is wanted depends entirely on
            // whether the recipient is going to look at it or work on it.
            $table->enum('format', ['pdf', 'excel'])->default('pdf');

            // Plain addresses. See the note above on why these are not user ids.
            $table->json('recipients');

            // Optional narrowing, matching the on-screen filter. A branch
            // manager wants their branch, not the company.
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_sent_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The command's own query: everything live for one company.
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscriptions');
    }
};
