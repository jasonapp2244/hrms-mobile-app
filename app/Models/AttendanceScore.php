<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScore extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'period', 'period_type', 'present_days', 'late_count',
        'absent_count', 'leave_days', 'early_leave_count', 'ontime_pct', 'score',
    ];

    /**
     * A score belongs to the company its employee does.
     *
     * Filled here rather than at the call site, for the same reason as on
     * AttendanceLog: the value is never in doubt, and a row without it is
     * invisible to every company-scoped report.
     */
    protected static function booted(): void
    {
        static::creating(function (self $score) {
            $score->company_id ??= Employee::whereKey($score->employee_id)->value('company_id');
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
