<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee shift override.
 *
 * Shifts have been assigned per department since Phase 2's first commit, which
 * is right for most people and wrong for the ones who need it most — a night
 * guard in Operations, or a part-timer in a nine-to-five team. This is the
 * exception, not a replacement: null means "whatever the department is on", so
 * moving a department's shift still moves everyone who has not been singled out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('designation_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
