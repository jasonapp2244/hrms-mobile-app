<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee, per leave type, per year.
 *
 * available = entitled_days + carried_forward - used_days
 *
 * `used_days` is maintained as approved requests are granted and cancelled, so a
 * balance check never has to sum the whole request history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->year('year');

            $table->decimal('entitled_days', 5, 1)->default(0);
            $table->decimal('carried_forward', 5, 1)->default(0);
            $table->decimal('used_days', 5, 1)->default(0);

            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
