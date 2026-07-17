<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Shift extends Model
{
    protected $fillable = [
        'company_id', 'name', 'code', 'start_time', 'end_time',
        'break_minutes', 'late_grace_minutes', 'color', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Departments assigned to this shift. */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /** Employees on this shift — reached through their department. */
    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(Employee::class, Department::class);
    }

    /** "09:00 - 17:00" for display. */
    public function getTimingAttribute(): string
    {
        return \Illuminate\Support\Str::of($this->start_time)->substr(0, 5)
            . ' - ' . \Illuminate\Support\Str::of($this->end_time)->substr(0, 5);
    }
}
