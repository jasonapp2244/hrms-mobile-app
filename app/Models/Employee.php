<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'company_id', 'office_id', 'department_id', 'designation_id', 'manager_id',
        'shift_id', 'user_id', 'employee_code', 'first_name', 'last_name', 'email',
        'phone', 'avatar', 'date_of_birth', 'gender', 'hire_date', 'status', 'work_mode',
        // A3.9 — personal details and the emergency contact.
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'personal_email', 'address', 'city', 'country', 'national_id', 'blood_group',
    ];

    /**
     * A usable photo URL, or null.
     *
     * Null rather than a placeholder image path: the views already fall back to
     * initials, which read better than a grey silhouette repeated down a list,
     * and deciding that here would take the choice away from them.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->avatar ? \Illuminate\Support\Facades\Storage::url($this->avatar) : null;
    }

    /** Human labels for the work_mode enum. */
    public const WORK_MODES = [
        'office' => 'Office (on-site)',
        'wfh'    => 'Work From Home',
        'hybrid' => 'Hybrid',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'hire_date' => 'date',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * A shift assigned to this employee specifically, overriding their
     * department's. Null for almost everyone.
     */
    public function shiftOverride(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /** Planned days on the roster. */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * The employee's standing shift, ignoring the roster.
     *
     * Their own overrides the department's, and the department's is the default.
     * This is what applies on any day nobody has planned.
     */
    public function getShiftAttribute(): ?Shift
    {
        return $this->shiftOverride ?? $this->department?->shift;
    }

    /**
     * The shift this employee works on a given date.
     *
     * A planned day wins over the standing shift, which is the whole point of
     * the roster — otherwise every week would look identical and a rotation
     * could not be expressed. A rostered day off returns null, exactly like
     * having no shift at all, because for judging a punch they are the same
     * thing: no hours to be measured against.
     *
     * Everything that judges a punch reads this, so there is one answer to
     * "what hours is this person on, that day" rather than one per caller.
     */
    public function shiftOn(string $date): ?Shift
    {
        $assignment = $this->relationLoaded('shiftAssignments')
            ? $this->shiftAssignments->first(fn (ShiftAssignment $a) => $a->date->toDateString() === $date)
            : $this->shiftAssignments()->whereDate('date', $date)->first();

        if ($assignment) {
            return $assignment->is_day_off ? null : $assignment->shift;
        }

        return $this->shift;
    }

    /** Whether this employee is rostered off on a date. */
    public function isRosteredOff(string $date): bool
    {
        return (bool) $this->shiftAssignments()
            ->whereDate('date', $date)->value('is_day_off');
    }

    /** Whether this employee is on a shift of their own rather than the team's. */
    public function hasShiftOverride(): bool
    {
        return $this->shift_id !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    /** This employee's line manager, if one is set. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /** Direct reports. Does not recurse — one level only. */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    /** True when this employee has anyone reporting to them. */
    public function isManager(): bool
    {
        return $this->subordinates()->exists();
    }

    /**
     * Whether this employee may be assigned the given manager.
     *
     * Rejects self-management and any assignment that would close a loop in the
     * reporting chain (A reports to B reports to A). A cycle would hang every
     * recursive walk of the org chart, so it has to be caught before it is saved.
     */
    public function canReportTo(?int $managerId): bool
    {
        if ($managerId === null) {
            return true;
        }

        if ($managerId === $this->id) {
            return false;
        }

        // Walk up from the proposed manager. If we reach this employee, the new
        // edge would close a loop. The visited set also breaks out of any cycle
        // already present in the data rather than looping forever on it.
        $visited = [];
        $current = static::find($managerId);

        while ($current !== null) {
            if ($current->id === $this->id) {
                return false;
            }

            if (isset($visited[$current->id])) {
                break;
            }
            $visited[$current->id] = true;

            $current = $current->manager_id ? static::find($current->manager_id) : null;
        }

        return true;
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** Filed contracts, ID scans and certificates (A3.8). */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * Take the filed documents — and their files — with the record.
     *
     * The database cascade already removes the rows, but a cascade is invisible
     * to Eloquent, so EmployeeDocument's own deleting hook never runs and the
     * files stay on disk for ever. Deleting them through the model here is what
     * makes "the employee is gone" mean their passport scan is gone too.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $employee) {
            $employee->documents->each->delete();
        });
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** Approved leave covering a given date, if any. */
    public function leaveOn(string $date): ?LeaveRequest
    {
        return $this->leaveRequests()
            ->approved()
            ->overlapping($date, $date)
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
