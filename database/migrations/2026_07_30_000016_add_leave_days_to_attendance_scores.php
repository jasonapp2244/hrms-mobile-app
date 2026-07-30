<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days accounted for by approved leave in the scored period.
 *
 * absent_count now excludes these. Without storing the figure the drop would be
 * unexplainable on screen — "you were absent 2 days" reads very differently
 * next to "and on leave for 3".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_scores', function (Blueprint $table) {
            $table->unsignedInteger('leave_days')->default(0)->after('absent_count');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_scores', function (Blueprint $table) {
            $table->dropColumn('leave_days');
        });
    }
};
