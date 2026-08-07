<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A4.15 — break in / break out punches.
 *
 * Widens attendance_logs.type from ('in','out') to include the two break
 * markers. Written as raw ALTER rather than through Blueprint because Doctrine
 * cannot modify an enum in place, and SQLite — which the test suite runs on —
 * has no enum at all: it stores the column as a varchar with a check
 * constraint, so there is nothing to widen there and the change is a no-op.
 *
 * Existing rows are untouched. A day recorded before this migration has no
 * break punches, which is handled as "break not tracked" rather than "no break
 * taken" — see AttendanceService::overtimeFor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE attendance_logs MODIFY COLUMN type ENUM('in','out','break_start','break_end') NOT NULL",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Break rows would violate the narrowed column, and silently dropping
        // punches to make a rollback succeed is not a trade worth making.
        $breaks = DB::table('attendance_logs')->whereIn('type', ['break_start', 'break_end'])->count();

        if ($breaks > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$breaks} break punch(es) exist. Void or migrate them first.",
            );
        }

        DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN type ENUM('in','out') NOT NULL");
    }
};
