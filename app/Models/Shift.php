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
        'break_minutes', 'break_is_paid', 'break_is_minimum',
        'late_grace_minutes', 'color', 'is_active',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'break_is_paid'    => 'boolean',
        'break_is_minimum' => 'boolean',
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

    /** Paid minutes on shift — span less the break, when the break is unpaid. */
    public function workingMinutes(): int
    {
        $start = \Carbon\Carbon::parse($this->start_time);
        $end   = \Carbon\Carbon::parse($this->end_time);

        if ($this->crossesMidnight()) {
            $end->addDay();
        }

        // A paid break is on the clock, so it stays in the scheduled figure.
        // Taking it out here while leaving it in the worked figure would put
        // the two on different footings and manufacture a break's worth of
        // overtime on every such shift, every day.
        $unpaid = $this->break_is_paid ? 0 : (int) $this->break_minutes;

        return max(0, $start->diffInMinutes($end) - $unpaid);
    }

    /**
     * Minutes to take off a **finished** day's paid time for breaks (A5.7).
     *
     * The one place the shift's break policy is read. Three answers, and which
     * one applies is the company's decision rather than arithmetic:
     *
     * - **Paid break** — nothing, ever. The break is worked time.
     * - **No break punched** — the nominal break, because the day was worked
     *   under a shift that says the break is unpaid whether or not anybody
     *   pressed a button. Without this an ordinary 09:00–17:00 day reports 480
     *   worked against 450 scheduled and manufactures half an hour of overtime
     *   for everybody.
     * - **A break punched** — what was actually taken, or the nominal break if
     *   that is longer and the shift treats it as a minimum.
     *
     * [$hasPunches] is passed rather than inferred from [$punched] because a
     * break punched and ended in the same minute is a real, zero-length break
     * and is not the same fact as no break at all.
     */
    public function settledBreakDeduction(int $punched, bool $hasPunches): int
    {
        if ($this->break_is_paid) {
            return 0;
        }

        $nominal = (int) $this->break_minutes;

        if (! $hasPunches) {
            return $nominal;
        }

        return $this->break_is_minimum ? max($punched, $nominal) : $punched;
    }

    /**
     * Minutes to take off for breaks **actually taken**, and nothing more.
     *
     * What a live counter uses, because the nominal break has no business in
     * one: deducting it at five past nine would show somebody losing half an
     * hour to a lunch they have not had yet, and the minimum rule cannot be
     * judged until the break is over.
     */
    public function actualBreakDeduction(int $punched): int
    {
        return $this->break_is_paid ? 0 : $punched;
    }

    /** How the break reads on a list, in one phrase. */
    public function getBreakPolicyLabelAttribute(): string
    {
        if (! $this->break_minutes) {
            return 'No break';
        }

        $minutes = (int) $this->break_minutes;

        if ($this->break_is_paid) {
            return "{$minutes}m paid";
        }

        return $this->break_is_minimum
            ? "{$minutes}m unpaid (minimum)"
            : "{$minutes}m unpaid";
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
