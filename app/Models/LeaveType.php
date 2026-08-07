<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = [
        'company_id', 'name', 'code', 'days_per_year', 'accrual_mode', 'is_paid',
        'requires_approval', 'allow_half_day', 'carry_forward_max',
        'color', 'is_active',
    ];

    /** How entitlement arrives over the year (A6.4). */
    public const ACCRUAL_MODES = [
        'upfront' => 'All at once, at the start of the year',
        'monthly' => 'A twelfth each month, pro-rated from the hire date',
    ];

    public function accruesMonthly(): bool
    {
        return $this->accrual_mode === 'monthly';
    }

    protected $casts = [
        'days_per_year'     => 'decimal:1',
        'carry_forward_max' => 'decimal:1',
        'is_paid'           => 'boolean',
        'requires_approval' => 'boolean',
        'allow_half_day'    => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
