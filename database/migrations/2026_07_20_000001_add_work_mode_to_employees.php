<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * work_mode controls how an employee is allowed to clock in:
     *  - office : works on-site (location may be checked in future)
     *  - wfh    : works from home, clocks in from anywhere
     *  - hybrid : mix of both; punches are labelled but never blocked
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('work_mode', ['office', 'wfh', 'hybrid'])
                ->default('office')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
    }
};
