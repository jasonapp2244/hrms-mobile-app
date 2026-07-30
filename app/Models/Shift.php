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

    /**
     * A night shift: it ends on the following calendar day.
     *
     * Derived from the times rather than stored, so it cannot fall out of step
     * with them. 22:00–06:00 crosses; 09:00–17:00 does not. Equal times are read
     * as a full 24 hours, which also crosses.
     */
    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /**
     * Cut-off separating a night shift's own hours from the next day's.
     *
     * A punch before this on a crossing shift belongs to the shift that started
     * yesterday. Noon is used rather than the exact end time so somebody who
     * clocks out late still lands on the right day.
     */
    public const NIGHT_CUTOFF_HOUR = 12;

    /** Paid minutes on shift — span less the unpaid break. */
    public function workingMinutes(): int
    {
        $start = \Carbon\Carbon::parse($this->start_time);
        $end   = \Carbon\Carbon::parse($this->end_time);

        if ($this->crossesMidnight()) {
            $end->addDay();
        }

        return max(0, $start->diffInMinutes($end) - (int) $this->break_minutes);
    }

    /** "7h 30m" for display. */
    public function getWorkingHoursAttribute(): string
    {
        $minutes = $this->workingMinutes();

        return intdiv($minutes, 60) . 'h' . ($minutes % 60 ? ' ' . $minutes % 60 . 'm' : '');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
