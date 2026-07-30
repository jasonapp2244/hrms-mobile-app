<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring attendance_logs into line with every other company-scoped table.
 *
 * The development database already carried this column, correctly backfilled,
 * but no migration declared it and no code set it — so every fresh install had
 * a different schema, and on the database that did have it a punch failed
 * outright with "Field 'company_id' doesn't have a default value". The web
 * button and the mobile API were equally broken by it.
 *
 * Guarded on hasColumn so it is a no-op where the column is already present,
 * rather than fighting the state it was added in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attendance_logs', 'company_id')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            // Nullable first: existing rows have no value yet, and a NOT NULL
            // column cannot be added to a table that already has any.
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            $table->index(['company_id', 'work_date']);
        });

        // Every log belongs to the company its employee belongs to. There is no
        // ambiguity to resolve — the value was simply never recorded.
        DB::table('attendance_logs')->orderBy('id')->chunkById(500, function ($logs) {
            foreach ($logs as $log) {
                DB::table('attendance_logs')
                    ->where('id', $log->id)
                    ->update([
                        'company_id' => DB::table('employees')
                            ->where('id', $log->employee_id)
                            ->value('company_id'),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'work_date']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
