<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step the company always takes when somebody joins or leaves (A3.12). */
class ChecklistTemplate extends Model
{
    protected $fillable = [
        'company_id', 'kind', 'title', 'description',
        'owner', 'due_offset_days', 'position', 'is_active',
    ];

    protected $casts = [
        'due_offset_days' => 'integer',
        'position'        => 'integer',
        'is_active'       => 'boolean',
    ];

    public const KINDS = [
        'onboarding'  => 'Joining',
        'offboarding' => 'Leaving',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** "3 days before", "on the day", "2 weeks after". */
    public function getTimingAttribute(): string
    {
        $days = $this->due_offset_days;

        if ($days === 0) {
            return 'On the day';
        }

        $count = abs($days);
        $unit = $count % 7 === 0 && $count >= 7
            ? ($count / 7) . ' week' . ($count === 7 ? '' : 's')
            : $count . ' day' . ($count === 1 ? '' : 's');

        return $unit . ($days < 0 ? ' before' : ' after');
    }
}
