<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same repair as attendance_logs, on the table next to it.
 *
 * The development database carried a NOT NULL company_id that no migration
 * declared and no code set. Existing rows updated fine, so nothing looked
 * wrong — but every *insert* failed, which meant a score could never be
 * created for a new employee, and on the first of any month every employee's
 * score would fail at once.
 *
 * Guarded on hasColumn so it is a no-op where the column is already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attendance_scores', 'company_id')) {
            return;
        }

        Schema::table('attendance_scores', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            $table->index(['company_id', 'period']);
        });

        // A score belongs to the company its employee belongs to.
        DB::table('attendance_scores')->orderBy('id')->chunkById(500, function ($scores) {
            foreach ($scores as $score) {
                DB::table('attendance_scores')
                    ->where('id', $score->id)
                    ->update([
                        'company_id' => DB::table('employees')
                            ->where('id', $score->employee_id)
                            ->value('company_id'),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_scores', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'period']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
