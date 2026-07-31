<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One entry in the attendance trail. Written once, never changed.
 */
class AttendanceAuditEvent extends Model
{
    public const CREATED   = 'created';
    public const CORRECTED = 'corrected';
    public const VOIDED    = 'voided';

    /** Audit rows record when they happened, never when they were last touched. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'attendance_log_id', 'employee_id', 'event',
        'actor_user_id', 'actor_label', 'source', 'reason',
        'before', 'after', 'ip_address',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * An audit row that can be rewritten is not evidence of anything.
     *
     * Enforced in the application rather than left to convention, because the
     * whole value of the table is that a reader can trust it was not edited after
     * the fact. Direct SQL can still reach it — that is what database permissions
     * are for — but nothing in this codebase can do it by accident.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Audit events are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Audit events are immutable and cannot be deleted.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Who caused this, for display. Falls back to the system when no one was signed in. */
    public function getActorNameAttribute(): string
    {
        return $this->actor_label ?? $this->actor?->name ?? 'System';
    }
}
