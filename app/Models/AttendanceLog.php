<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    protected $fillable = [
        'employee_id', 'office_id', 'type', 'scanned_at', 'work_date',
        'status', 'source', 'latitude', 'longitude', 'ip_address', 'notes',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'work_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
