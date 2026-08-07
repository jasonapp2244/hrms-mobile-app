<?php

namespace App\Models;

use App\Exceptions\AttendanceIsImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceLog extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'office_id', 'type', 'scanned_at', 'work_date',
        'status', 'source', 'latitude', 'longitude', 'ip_address', 'notes',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'work_date' => 'date',
        'voided_at' => 'datetime',
    ];

    /**
     * The one mutation this model permits, and only from void().
     *
     * The updating guard below throws for everything else. Rather than let the
     * guard consult a caller-supplied flag — which any call site could set —
     * the window is opened here for the duration of a single save and closed
     * in a finally block. Nothing outside this class can widen it.
     */
    private bool $voidingInProgress = false;

    /**
     * A log always belongs to the company its employee does.
     *
     * Filled here rather than at each call site because punches are created
     * from several places — the portal button, the mobile API, seeders and
     * imports — and a caller that forgets produces a row that no company-scoped
     * query can see. There is nothing to decide: the answer is the employee's
     * company, every time.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->company_id ??= Employee::whereKey($log->employee_id)->value('company_id');
        });

        // Every punch leaves a trail entry, written here rather than at the two
        // call sites for the same reason company_id is: punches arrive from the
        // portal, the mobile API, the nightly close, seeders and imports, and a
        // trail with gaps in it cannot be relied on precisely when it matters.
        static::created(function (self $log) {
            AttendanceAuditEvent::create([
                'company_id'        => $log->company_id,
                'attendance_log_id' => $log->id,
                'employee_id'       => $log->employee_id,
                'event'             => AttendanceAuditEvent::CREATED,
                'actor_user_id'     => auth()->id(),
                'actor_label'       => auth()->user()?->name,
                'source'            => $log->source,
                'before'            => null,
                'after'             => $log->auditSnapshot(),
                'ip_address'        => $log->ip_address,
            ]);
        });

        // Attendance is append-only. It already was in practice — nothing in the
        // codebase edits a punch — but "nobody wrote that code yet" is not a
        // guarantee anyone can rely on in a dispute over someone's hours.
        //
        // void() is the single sanctioned exception, and it still does not edit
        // what the punch claims: the type, time, status and location are never
        // touched. It only strikes the row out and records who did it and why.
        // A correction is therefore always void-then-re-enter, which leaves both
        // the wrong reading and the right one on the record.
        static::updating(function (self $log) {
            if ($log->voidingInProgress) {
                return;
            }

            throw new AttendanceIsImmutable(
                'An attendance record cannot be edited. Void it and record a correction instead.',
            );
        });

        static::deleting(function () {
            throw new AttendanceIsImmutable(
                'An attendance record cannot be deleted. Void it instead so the trail survives.',
            );
        });

        // Voided punches leave every ordinary read.
        //
        // A global scope rather than a scope each caller opts into, because
        // there are twenty-odd query sites across services, reports, the API,
        // the nightly close and the dashboard. Forgetting withVoided() shows a
        // reader too little and is obvious; forgetting notVoided() would let a
        // struck-out punch count towards somebody's worked hours and would not
        // be obvious at all. The failure modes are not symmetric, so the safe
        // one is the default.
        static::addGlobalScope('notVoided', function (Builder $query) {
            $query->whereNull($query->getModel()->qualifyColumn('voided_at'));
        });
    }

    /** Include struck-out punches — for the audit trail and the void log. */
    public function scopeWithVoided(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notVoided');
    }

    /** Only struck-out punches. */
    public function scopeOnlyVoided(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notVoided')->whereNotNull('voided_at');
    }

    /**
     * Punches on the work dates in an inclusive range.
     *
     * whereDate on both ends rather than whereBetween, for the same reason
     * LeaveRequest::scopeOverlapping does it: `work_date` is a real DATE column
     * in MySQL, but the date cast writes "2026-08-04 00:00:00" on any engine
     * without one, and "2026-08-04 00:00:00" <= "2026-08-04" is false as a
     * string. The last day of every range quietly vanished — invisible in
     * production, and equally invisible in the tests, which run on SQLite and
     * were therefore measuring a slightly different report than the one shipping.
     *
     * A single-day range — which is exactly what a daily scheduled report asks
     * for — returned nothing at all.
     */
    public function scopeForDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Strike this punch out. The row survives; it simply stops counting.
     *
     * @throws AttendanceIsImmutable if it has already been voided — voiding
     *         twice would overwrite the first actor and reason, which is
     *         exactly the kind of quiet rewrite this table exists to prevent.
     */
    public function void(User $actor, string $reason): self
    {
        if ($this->isVoided()) {
            throw new AttendanceIsImmutable('This punch has already been voided.');
        }

        $before = $this->auditSnapshot();

        $this->voidingInProgress = true;

        try {
            $this->forceFill([
                'voided_at'         => now(),
                'voided_by_user_id' => $actor->id,
                'voided_by_label'   => $actor->name,
                'void_reason'       => $reason,
            ])->save();
        } finally {
            $this->voidingInProgress = false;
        }

        AttendanceAuditEvent::create([
            'company_id'        => $this->company_id,
            'attendance_log_id' => $this->id,
            'employee_id'       => $this->employee_id,
            'event'             => AttendanceAuditEvent::VOIDED,
            'actor_user_id'     => $actor->id,
            'actor_label'       => $actor->name,
            'source'            => $this->source,
            'reason'            => $reason,
            'before'            => $before,
            'after'             => $this->auditSnapshot(),
            'ip_address'        => request()?->ip(),
        ]);

        return $this;
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /** The fields worth keeping in the trail — what the record claims, not its plumbing. */
    public function auditSnapshot(): array
    {
        return [
            'type'       => $this->type,
            'scanned_at' => $this->scanned_at?->toDateTimeString(),
            'work_date'  => $this->work_date?->toDateString(),
            'status'     => $this->status,
            'office_id'  => $this->office_id,
            'source'     => $this->source,
            'latitude'   => $this->latitude,
            'longitude'  => $this->longitude,
        ];
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AttendanceAuditEvent::class);
    }

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
}
