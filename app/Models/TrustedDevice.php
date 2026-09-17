<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The handset an account is bound to (B1.6).
 *
 * See the migration for why this is its own table and why `device_id` is an
 * app-generated UUID rather than a hardware identifier.
 *
 * **The whole model is off unless the company turns it on.** `enforce_device_binding`
 * defaults to false, like every other policy here: switching on a control that
 * can refuse a sign-in has to be somebody's decision, not a surprise after an
 * update.
 */
class TrustedDevice extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'device_id', 'device_name', 'platform',
        'trusted_at', 'last_seen_at', 'released_at', 'released_by_user_id',
    ];

    protected $casts = [
        'trusted_at'   => 'datetime',
        'last_seen_at' => 'datetime',
        'released_at'  => 'datetime',
    ];

    /** Still binding. A released row is history and binds nobody. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * Decide whether this handset may sign this account in, and record it if
     * this is the first.
     *
     * Three outcomes, and the middle one is what makes the feature deployable:
     *
     *  - **No binding yet** → trust this handset and let them in. A company that
     *    switches the policy on must not lock out its entire workforce on the
     *    Monday morning it does so; each person's next sign-in claims their own
     *    phone, and the control starts biting from the second one.
     *  - **Bound to this handset** → let them in, and note that it was seen.
     *  - **Bound to another** → refuse, and say so in words that name the fix.
     *    This is the buddy-punch case and the stolen-password case, and they
     *    look identical from here, which is why the message points at HR rather
     *    than accusing anybody.
     *
     * @return array{allowed: bool, device: self|null, first: bool}
     */
    public static function admit(User $user, string $deviceId, array $meta = []): array
    {
        $bound = static::query()->active()->where('user_id', $user->id)->get();

        $match = $bound->firstWhere('device_id', $deviceId);

        if ($match) {
            $match->forceFill([
                'last_seen_at' => now(),
                // Kept fresh: somebody renames their phone, or the app learns a
                // better name after an OS upgrade.
                'device_name' => $meta['device_name'] ?? $match->device_name,
                'platform'    => $meta['platform'] ?? $match->platform,
            ])->save();

            return ['allowed' => true, 'device' => $match, 'first' => false];
        }

        if ($bound->isNotEmpty()) {
            return ['allowed' => false, 'device' => $bound->first(), 'first' => false];
        }

        $device = static::create([
            'company_id'   => $user->company_id,
            'user_id'      => $user->id,
            'device_id'    => $deviceId,
            'device_name'  => $meta['device_name'] ?? null,
            'platform'     => $meta['platform'] ?? null,
            'trusted_at'   => now(),
            'last_seen_at' => now(),
        ]);

        return ['allowed' => true, 'device' => $device, 'first' => true];
    }

    /**
     * Stop this handset binding the account.
     *
     * Set, never deleted: who was trusted and when that stopped is exactly what
     * somebody asks after a dispute, and a deleted row answers neither.
     */
    public function release(User $by): void
    {
        $this->forceFill([
            'released_at'         => now(),
            'released_by_user_id' => $by->id,
        ])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}
