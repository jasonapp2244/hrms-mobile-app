<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The last table left out of the company_id sweep.
 *
 * Same defect as attendance_logs and attendance_scores before it: the deployed
 * database carried a NOT NULL company_id that no migration declared, so a fresh
 * install and a running install had different schemas. Here it was worse than a
 * cosmetic drift — nothing in the application ever set the column, so the insert
 * in LeaveService::balanceFor() failed outright with "Field 'company_id' doesn't
 * have a default value".
 *
 * That method is the single door into every balance row, so the failure took the
 * whole leave module with it the moment a year had no balances yet: an employee
 * applying, the mobile API applying, and HR's own "generate balances" button all
 * returned a 500, leaving no way to recover from inside the app. The seeded rows
 * hid it, because the seeder set the column itself.
 *
 * The test suite could not see it. SQLite builds from these migrations, which did
 * not declare the column, so the insert simply succeeded there.
 *
 * Guarded on hasColumn so it is a no-op where the column already exists, rather
 * than fighting the state it was added in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leave_balances', 'company_id')) {
            return;
        }

        Schema::table('leave_balances', function (Blueprint $table) {
            // Nullable first: existing rows have no value yet, and a NOT NULL
            // column cannot be added to a table that already has any.
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            $table->index(['company_id', 'year']);
        });

        // A balance belongs to the company its employee belongs to. There is no
        // ambiguity to resolve — the value was simply never recorded.
        DB::table('leave_balances')->orderBy('id')->chunkById(500, function ($balances) {
            foreach ($balances as $balance) {
                DB::table('leave_balances')
                    ->where('id', $balance->id)
                    ->update([
                        'company_id' => DB::table('employees')
                            ->where('id', $balance->employee_id)
                            ->value('company_id'),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'year']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
