<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** "09:00 - 17:00" for display. */
    public function getTimingAttribute(): string
    {
        return \Illuminate\Support\Str::of($this->start_time)->substr(0, 5)
            . ' - ' . \Illuminate\Support\Str::of($this->end_time)->substr(0, 5);
    }
}
