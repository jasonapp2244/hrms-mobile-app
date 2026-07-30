<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manager step of the approval chain.
 *
 * `approved_by` / `approved_at` already record the final decision. These record
 * the one before it, so a request that a manager has passed on but HR has not
 * yet seen is distinguishable from one nobody has touched.
 *
 * No stage column: which step a request is waiting on is derived from these
 * fields plus whether the employee has a manager at all. A stored stage would be
 * a second source of truth to keep in sync with the one that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Nullified rather than cascaded, matching approved_by: the request
            // must survive the approver's account being deleted.
            $table->foreignId('manager_approved_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('manager_approved_at')->nullable()->after('manager_approved_by');
            $table->text('manager_note')->nullable()->after('manager_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_approved_by');
            $table->dropColumn(['manager_approved_at', 'manager_note']);
        });
    }
};
