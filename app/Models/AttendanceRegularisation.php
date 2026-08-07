<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's request to have their attendance record corrected (A4.13).
 *
 * Deliberately inert: approving one does not write attendance here. That is
 * RegularisationService's job, and it goes through the same void and manual
 * entry paths HR uses by hand — so a punch produced by an approval is
 * indistinguishable, in the audit trail, from one keyed in directly. There is
 * no second way into the attendance table.
 */
class AttendanceRegularisation extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'attendance_log_id', 'office_id',
        'work_date', 'type', 'requested_at', 'reason', 'status',
        'decided_by_user_id', 'decided_by_label', 'decided_at', 'decision_note',
        'created_log_id',
    ];

    protected $casts = [
        'work_date'    => 'date',
        'requested_at' => 'datetime',
        'decided_at'   => 'datetime',
    ];

    /**
     * Mirrors the column default. Without it a freshly built request has a null
     * status in memory until reloaded, which the accessors below would trip on.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    public const STATUSES = [
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_BADGES = [
        'pending'   => 'warning',
        'approved'  => 'success',
        'rejected'  => 'danger',
        'cancelled' => 'secondary',
    ];

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Whether this challenges an existing punch rather than reporting a missing one. */
    public function challengesAPunch(): bool
    {
        return $this->attendance_log_id !== null;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The punch being challenged.
     *
     * withVoided() on purpose: once approved, that punch has been struck out and
     * the global scope would hide it — leaving the request pointing at nothing
     * on the very screen meant to explain what happened to it.
     */
    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class)->withVoided();
    }

    /** The punch this request produced, once approved. */
    public function createdLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'created_log_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
