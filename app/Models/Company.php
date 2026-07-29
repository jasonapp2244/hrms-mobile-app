<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'website', 'logo', 'address',
        'city', 'country', 'timezone', 'currency', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * A guaranteed-valid timezone identifier for date math. Falls back to the
     * app default if the stored value is empty or not a real timezone, so bad
     * data can never crash now()/Carbon::now() with an "Unknown timezone" error.
     */
    public function tz(): string
    {
        return ($this->timezone && in_array($this->timezone, timezone_identifiers_list(), true))
            ? $this->timezone
            : config('app.timezone');
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
