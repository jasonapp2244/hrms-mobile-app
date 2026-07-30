<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'shift_id', 'date',
        'is_day_off', 'note', 'published_at', 'created_by',
    ];

    protected $casts = [
        'date'         => 'date',
        'is_day_off'   => 'boolean',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'is_day_off' => false,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    /** Assignments falling inside a date range. */
    public function scopeBetween($query, string $from, string $to)
    {
        return $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }
}
