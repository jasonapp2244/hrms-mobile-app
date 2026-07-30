<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    protected $fillable = [
        'company_id', 'requester_id', 'requester_date', 'target_id', 'target_date',
        'reason', 'status', 'responded_at', 'response_note',
        'approved_by', 'approved_at', 'decision_note',
    ];

    protected $casts = [
        'requester_date' => 'date',
        'target_date'    => 'date',
        'responded_at'   => 'datetime',
        'approved_at'    => 'datetime',
    ];

    /** Mirrors the column default so a fresh instance reads correctly in memory. */
    protected $attributes = [
        'status' => 'pending',
    ];

    public const STATUSES = [
        'pending'   => 'Awaiting Colleague',
        'accepted'  => 'Awaiting Approval',
        'approved'  => 'Approved',
        'declined'  => 'Declined',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_BADGES = [
        'pending'   => 'secondary',
        'accepted'  => 'warning',
        'approved'  => 'success',
        'declined'  => 'danger',
        'rejected'  => 'danger',
        'cancelled' => 'secondary',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'target_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'secondary';
    }

    /** Waiting on the colleague to accept or decline. */
    public function isAwaitingColleague(): bool
    {
        return $this->status === 'pending';
    }

    /** Agreed between the two, waiting on a manager or HR. */
    public function isAwaitingApproval(): bool
    {
        return $this->status === 'accepted';
    }

    /** Still in play, either way. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'accepted'], true);
    }

    /** Both people are swapping the same date — a straight exchange of shifts. */
    public function isSameDay(): bool
    {
        return $this->requester_date->isSameDay($this->target_date);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['pending', 'accepted']);
    }

    public function scopeAwaitingApproval($query)
    {
        return $query->where('status', 'accepted');
    }
}
