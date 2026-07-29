<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'company_id', 'office_id', 'department_id', 'designation_id', 'manager_id',
        'user_id', 'employee_code', 'first_name', 'last_name', 'email', 'phone',
        'avatar', 'date_of_birth', 'gender', 'hire_date', 'status', 'work_mode',
    ];

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

    /** An employee's working shift is inherited from their department. */
    public function getShiftAttribute(): ?Shift
    {
        return $this->department?->shift;
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

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** Approved leave covering a given date, if any. */
    public function leaveOn(string $date): ?LeaveRequest
    {
        return $this->leaveRequests()
            ->approved()
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
