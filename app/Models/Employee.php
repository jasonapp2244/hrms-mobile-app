<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'company_id', 'office_id', 'department_id', 'designation_id', 'user_id',
        'employee_code', 'first_name', 'last_name', 'email', 'phone', 'avatar',
        'date_of_birth', 'gender', 'hire_date', 'status', 'work_mode',
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
