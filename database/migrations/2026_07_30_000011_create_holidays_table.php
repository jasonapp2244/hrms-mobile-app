<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company holiday calendar. Needed by leave so a public holiday inside a leave
 * range is not deducted from the employee's balance, and later by the auto-absent
 * job so nobody is marked absent on a day the office was closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            // Recurring holidays fall on the same day/month every year (e.g. 1 Jan).
            // Non-recurring ones move (e.g. Eid), so they are entered per year.
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
