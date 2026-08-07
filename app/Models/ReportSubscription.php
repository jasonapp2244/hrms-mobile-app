<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing order to email a report on a schedule (A7.12).
 *
 * The model owns the calendar arithmetic — which window a run covers, and
 * whether one is due — rather than the command, because both the command and
 * the management screen need to agree on it. A screen that says "next: 1 Sep"
 * while the command sends on the 2nd is a support ticket.
 */
class ReportSubscription extends Model
{
    protected $fillable = [
        'company_id', 'report_type', 'frequency', 'format',
        'recipients', 'office_id', 'is_active', 'last_sent_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'recipients'   => 'array',
        'is_active'    => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    /**
     * The reports that can be subscribed to, and what to call them.
     *
     * Keys are ReportController's dispatch keys. Anything not listed here
     * cannot be subscribed to, which is what keeps a hand-edited row from
     * calling an arbitrary method on ReportService.
     */
    public const REPORTS = [
        'late'       => 'Late Arrivals',
        'outliers'   => 'Attendance Outliers',
        'overtime'   => 'Overtime',
        'payroll'    => 'Payroll Hours',
        'leave'      => 'Leave',
        'weekly'     => 'Weekly Rollup',
        'department' => 'Department Attendance',
    ];

    public const FREQUENCIES = [
        'daily'   => 'Every day',
        'weekly'  => 'Every Monday',
        'monthly' => 'On the 1st',
    ];

    public const FORMATS = [
        'pdf'   => 'PDF',
        'excel' => 'Excel',
    ];

    /**
     * The hour, in the company's own timezone, that reports go out.
     *
     * Early enough to be waiting when people arrive, late enough that the
     * previous day's auto-close (which runs hourly through the night) has
     * certainly finished writing the punches this report counts.
     */
    public const SEND_HOUR = 7;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getReportLabelAttribute(): string
    {
        return self::REPORTS[$this->report_type] ?? $this->report_type;
    }

    public function getFrequencyLabelAttribute(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? $this->frequency;
    }

    /**
     * The period a run at this moment would cover, as ['from' => …, 'to' => …].
     *
     * Always a closed, finished period — yesterday, last week, last month. A
     * daily report sent at 07:00 covering "today" would report on an hour of
     * attendance and read as a catastrophe.
     */
    public function periodFor(CarbonInterface $now): array
    {
        return match ($this->frequency) {
            'weekly' => [
                'from' => $now->copy()->subWeek()->startOfWeek()->toDateString(),
                'to'   => $now->copy()->subWeek()->endOfWeek()->toDateString(),
            ],
            'monthly' => [
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to'   => $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [
                'from' => $now->copy()->subDay()->toDateString(),
                'to'   => $now->copy()->subDay()->toDateString(),
            ],
        };
    }

    /**
     * Is a send due, given the company's local time?
     *
     * Three gates, in cost order. The cheap calendar checks — right hour, right
     * day — come first; the "have we already sent this one" check needs the
     * period computed, so it comes last.
     *
     * The already-sent test asks whether this period has been sent, not whether
     * enough time has passed: a send counts if it happened after the period
     * closed. That reading survives a retried run, a clock moved back an hour,
     * and a server that was down at 07:00 and caught up at 11:00 — none of
     * which a fixed "not within 24 hours" interval handles.
     */
    public function isDue(CarbonInterface $now): bool
    {
        if (! $this->is_active || $this->recipients === []) {
            return false;
        }

        if ($now->hour < self::SEND_HOUR) {
            return false;
        }

        if ($this->frequency === 'weekly' && ! $now->isMonday()) {
            return false;
        }

        if ($this->frequency === 'monthly' && $now->day !== 1) {
            return false;
        }

        if (! $this->last_sent_at) {
            return true;
        }

        $periodEnd = Carbon::parse($this->periodFor($now)['to'], $now->getTimezone())->endOfDay();

        return $this->last_sent_at->copy()->setTimezone($now->getTimezone())->lt($periodEnd);
    }
}
