<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScore extends Model
{
    protected $fillable = [
        'employee_id', 'period', 'period_type', 'present_days', 'late_count',
        'absent_count', 'leave_days', 'early_leave_count', 'ontime_pct', 'score',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
