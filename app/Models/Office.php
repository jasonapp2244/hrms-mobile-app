<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Office extends Model
{
    protected $fillable = [
        'company_id', 'name', 'code', 'address', 'city',
        'latitude', 'longitude', 'geofence_radius', 'qr_secret',
        'work_start_time', 'work_end_time', 'late_grace_minutes', 'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Auto-generate a strong per-office secret for the rotating QR
        static::creating(function (Office $office) {
            if (empty($office->qr_secret)) {
                $office->qr_secret = Str::random(64);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
