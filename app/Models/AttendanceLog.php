<?php

namespace App\Models;

use App\Exceptions\AttendanceIsImmutable;
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
    ];

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
        // A correction by HR is a real requirement and will need a sanctioned
        // path through here that records the before and after. Until that exists
        // the guard stays absolute, so a correction cannot be added without
        // deliberately dealing with the trail.
        static::updating(function () {
            throw new AttendanceIsImmutable(
                'An attendance record cannot be edited. Record a correction instead.',
            );
        });

        static::deleting(function () {
            throw new AttendanceIsImmutable(
                'An attendance record cannot be deleted. Void it instead so the trail survives.',
            );
        });
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
