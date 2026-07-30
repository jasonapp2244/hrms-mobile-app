<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'office_id', 'type', 'scanned_at', 'work_date',
        'status', 'source', 'latitude', 'longitude', 'ip_address', 'notes',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'work_date' => 'date',
    ];

    /**
     * A log always belongs to the company its employee does.
     *
     * Filled here rather than at each call site because punches are created
     * from several places — the portal button, the mobile API, seeders and
     * imports — and a caller that forgets produces a row that no company-scoped
     * query can see. There is nothing to decide: the answer is the employee's
     * company, every time.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->company_id ??= Employee::whereKey($log->employee_id)->value('company_id');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
