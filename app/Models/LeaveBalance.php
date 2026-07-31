<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'year',
        'entitled_days', 'carried_forward', 'used_days',
    ];

    protected $casts = [
        'year'            => 'integer',
        'entitled_days'   => 'decimal:1',
        'carried_forward' => 'decimal:1',
        'used_days'       => 'decimal:1',
    ];

    /**
     * A balance always belongs to the company its employee does.
     *
     * Filled here rather than at each call site because balanceFor() creates a
     * row on first use from wherever leave is touched — the portal, the mobile
     * API, HR's generate button, seeders — and a caller that forgets produces a
     * row no company-scoped query can see. There is nothing to decide: the
     * answer is the employee's company, every time.
     */
    protected static function booted(): void
    {
        static::creating(function (self $balance) {
            $balance->company_id ??= Employee::whereKey($balance->employee_id)->value('company_id');
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** Days still available to book this year. */
    public function getAvailableAttribute(): float
    {
        return (float) $this->entitled_days
            + (float) $this->carried_forward
            - (float) $this->used_days;
    }

    /** Entitlement before anything is spent. */
    public function getTotalAttribute(): float
    {
        return (float) $this->entitled_days + (float) $this->carried_forward;
    }

    /** Proportion of the entitlement consumed, for progress bars. */
    public function getUsedPctAttribute(): float
    {
        $total = $this->total;

        return $total > 0 ? round(((float) $this->used_days / $total) * 100, 1) : 0.0;
    }

    public function scopeForYear($query, ?int $year = null)
    {
        return $query->where('year', $year ?? (int) date('Y'));
    }
}
