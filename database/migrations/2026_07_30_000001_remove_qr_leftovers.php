<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cleanup of the QR attendance feature, removed from scope on 2026-07-20.
 *
 * The QR engine, kiosk screen and PWA scanner were deleted in commit edcd0ac,
 * but two artefacts were left behind: the per-office `qr_secret` column and the
 * `configure-qr` permission. Neither is read by any code path. This drops both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('offices', 'qr_secret')) {
            Schema::table('offices', function (Blueprint $table) {
                $table->dropColumn('qr_secret');
            });
        }

        // Remove the orphaned permission. Spatie's pivot rows are removed by the
        // cascade on role_has_permissions.permission_id, so deleting the parent
        // is enough — but the cached permission map must be flushed afterwards.
        DB::table('permissions')
            ->where('name', 'configure-qr')
            ->where('guard_name', 'web')
            ->delete();

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('offices', 'qr_secret')) {
            Schema::table('offices', function (Blueprint $table) {
                // Nullable on the way back: the original column was NOT NULL, which
                // cannot be added to a table that already holds rows.
                $table->string('qr_secret', 64)->nullable()->after('geofence_radius');
            });
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'configure-qr', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        app()['cache']->forget('spatie.permission.cache');
    }
};
