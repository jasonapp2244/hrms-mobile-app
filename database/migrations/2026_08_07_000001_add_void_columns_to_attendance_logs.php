<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give HR a way to strike out a wrong punch without destroying it.
 *
 * Attendance is append-only: AttendanceLog refuses update and delete outright,
 * and its delete guard already says "Void it instead so the trail survives".
 * These are the columns that sentence was waiting for.
 *
 * A void is not a soft delete. A soft-deleted row is one the application is
 * pretending never existed; a voided punch is one that demonstrably did exist,
 * was wrong, and was struck out by a named person for a stated reason at a
 * known time. In a dispute over someone's pay that difference is the whole
 * point — which is why the reason is not nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('notes');

            // Nulled rather than cascaded if the actor's account is ever
            // removed: losing the administrator should not quietly un-void a
            // punch. voided_by_label keeps the human answer either way.
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')
                ->constrained('users')->nullOnDelete();

            $table->string('voided_by_label')->nullable()->after('voided_by_user_id');
            $table->string('void_reason', 500)->nullable()->after('voided_by_label');

            // Every read filters on this — the model applies a global scope so
            // a voided punch cannot leak into worked hours through one of the
            // twenty-odd query sites. Paired with employee_id because that is
            // how the rows are almost always reached.
            $table->index(['employee_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'voided_at']);
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'voided_by_label', 'void_reason']);
        });
    }
};
