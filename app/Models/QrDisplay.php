<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * A screen at an office that shows the check-in code (A4.21).
 *
 * Reached through a signed link rather than a sign-in: the tablet on the wall
 * belongs to nobody, and an HR session left open on it would be a signed-in
 * dashboard in a corridor. The signature names the row, so revoking the row
 * kills the link, and a new screen is a new row with a new link.
 */
class QrDisplay extends Model
{
    protected $fillable = [
        'company_id', 'office_id', 'name', 'created_by_user_id',
        'last_seen_at', 'revoked_at', 'revoked_by_user_id',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** The link HR opens on the screen. Permanent until the row is revoked. */
    public function url(): string
    {
        return URL::signedRoute('qr-display.show', ['display' => $this->id]);
    }

    public function revoke(User $by): void
    {
        $this->forceFill([
            'revoked_at'         => now(),
            'revoked_by_user_id' => $by->id,
        ])->save();
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(AttendanceQrToken::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
