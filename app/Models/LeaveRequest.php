<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LeaveRequest extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id',
        'start_date', 'end_date', 'days',
        'is_half_day', 'half_day_period',
        'reason', 'attachment', 'attachment_name', 'status',
        'manager_approved_by', 'manager_approved_at', 'manager_note',
        'approved_by', 'approved_at', 'decision_note',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'days'                => 'decimal:1',
        'is_half_day'         => 'boolean',
        'approved_at'         => 'datetime',
        'manager_approved_at' => 'datetime',
    ];

    /**
     * Mirrors the column defaults. Without these a freshly created request has a
     * null status in memory — the database default only applies on the way in —
     * which breaks the status accessors before the model is reloaded.
     */
    protected $attributes = [
        'status'      => 'pending',
        'days'        => 0,
        'is_half_day' => false,
    ];

    /**
     * The statuses a request can be in.
     *
     * **Keys, not words.** The values are the English fallback and the order
     * the filters are drawn in; what a person actually reads comes from the
     * `leave.status` translations through [getStatusLabelAttribute], because
     * the API answers in the caller's language now (C1.18).
     */
    public const STATUSES = [
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    /** Bootstrap badge class per status, for the views. */
    public const STATUS_BADGES = [
        'pending'   => 'warning',
        'approved'  => 'success',
        'rejected'  => 'danger',
        'cancelled' => 'secondary',
    ];

    /**
     * The disk supporting evidence lives on (B4.1). Private, like the document
     * vault: a sick note is medical information about a named person, and it
     * must not be reachable by guessing a URL.
     */
    public const ATTACHMENT_DISK = 'local';

    /** What may be attached, and how large. Matches the document vault. */
    public const ATTACHMENT_RULES = 'file|max:10240|mimes:pdf,jpg,jpeg,png,webp,doc,docx';

    /**
     * The file is deleted with the row.
     *
     * Here rather than in a controller because a request can go through a
     * cascade — deleting an employee takes their leave with it — and a sick
     * note left on disk after the record that explained it is gone is exactly
     * the file nobody will ever find again to remove.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $request) {
            if ($request->attachment) {
                Storage::disk(self::ATTACHMENT_DISK)->delete($request->attachment);
            }
        });
    }

    /** Whether there is a file, and it is still where the row says it is. */
    public function hasAttachment(): bool
    {
        return $this->attachment !== null
            && Storage::disk(self::ATTACHMENT_DISK)->exists($this->attachment);
    }

    /**
     * What to call the file on the way out.
     *
     * The uploaded name when there is one, and something honest built from the
     * path when there is not — a download that arrives called `bin` helps
     * nobody open it.
     */
    public function attachmentDownloadName(): string
    {
        if ($this->attachment_name) {
            return $this->attachment_name;
        }

        $extension = pathinfo((string) $this->attachment, PATHINFO_EXTENSION);

        return 'leave-request-' . $this->id . ($extension ? '.' . $extension : '');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** The user who made the final decision on this request. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The manager who passed this request on to HR, if it got that far. */
    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function getStatusLabelAttribute(): string
    {
        if (! array_key_exists($this->status, self::STATUSES)) {
            return $this->status;
        }

        return __('leave.status.' . $this->status);
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'secondary';
    }

    /** Only a pending request can still be acted on. */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Approved leave that has not started yet can still be withdrawn. */
    public function isCancellable(): bool
    {
        return $this->status === 'pending'
            || ($this->status === 'approved' && $this->start_date->isFuture());
    }

    /**
     * Waiting on the employee's line manager.
     *
     * An employee with no manager set skips this step entirely and goes straight
     * to HR — otherwise their request would sit in a queue that has no owner.
     */
    public function isAwaitingManager(): bool
    {
        return $this->status === 'pending'
            && $this->manager_approved_at === null
            && $this->employee?->manager_id !== null;
    }

    /** Waiting on HR/Admin for the final decision. */
    public function isAwaitingHr(): bool
    {
        return $this->status === 'pending' && ! $this->isAwaitingManager();
    }

    /** What the request is waiting on, for the status columns. */
    public function getStageLabelAttribute(): string
    {
        if ($this->status !== 'pending') {
            return $this->status_label;
        }

        return $this->isAwaitingManager()
            ? __('leave.stage.awaiting_manager')
            : __('leave.stage.awaiting_hr');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Requests overlapping a date range — used for conflict detection.
     *
     * whereDate rather than a plain comparison: the date casts mean the stored
     * value carries a 00:00:00 time on any engine without a real DATE type, and
     * "2026-08-05 00:00:00" <= "2026-08-05" is false as a string. MySQL truncates
     * so it never showed there; matching on the date holds on both.
     */
    public function scopeOverlapping($query, string $from, string $to)
    {
        return $query->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from);
    }
}
