<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One employee asking another to trade a rostered day.
 *
 * Two gates, in order: the colleague has to agree, and then a manager or HR has
 * to sanction it. The colleague's acceptance is the first gate, so unlike leave
 * there is no separate manager-then-HR chain — one approval after the accept is
 * enough, and either approver can give it.
 *
 * The dates are stored rather than pointing at shift_assignments rows: the plan
 * can be regenerated underneath a pending request, and a swap that silently
 * retargeted itself at whatever now sits on that date would be worse than one
 * that refuses because the roster moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('requester_id')->constrained('employees')->cascadeOnDelete();
            $table->date('requester_date');

            $table->foreignId('target_id')->constrained('employees')->cascadeOnDelete();
            $table->date('target_date');

            $table->text('reason')->nullable();

            // pending   — waiting on the colleague
            // accepted  — colleague agreed, waiting on a manager
            // approved  — sanctioned and applied to the roster
            // declined  — colleague said no
            // rejected  — manager said no
            // cancelled — requester withdrew it
            $table->enum('status', [
                'pending', 'accepted', 'approved', 'declined', 'rejected', 'cancelled',
            ])->default('pending');

            $table->dateTime('responded_at')->nullable();
            $table->text('response_note')->nullable();

            // Nullified rather than cascaded, matching leave: the request must
            // survive the approver's account being deleted.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['target_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swap_requests');
    }
};
