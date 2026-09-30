<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One code shown on an office screen, good for one scan (A4.21).
 *
 * Only the hash is stored — see the migration.
 */
class AttendanceQrToken extends Model
{
    protected $fillable = [
        'company_id', 'office_id', 'qr_display_id', 'token_hash', 'expires_at',
        'consumed_at', 'consumed_by_employee_id', 'attendance_log_id',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function display(): BelongsTo
    {
        return $this->belongsTo(QrDisplay::class, 'qr_display_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'consumed_by_employee_id');
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }
}
