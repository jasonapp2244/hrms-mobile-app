<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A handset that can receive push notifications.
 *
 * Nothing sends anything yet — Phase 5 owns delivery. This is the address book
 * it will read, recorded now so the app can register from its first release
 * rather than needing an update once notifications land.
 */
class PushDevice extends Model
{
    protected $fillable = [
        'user_id', 'token', 'platform', 'device_name', 'app_version', 'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /** Platforms the app can register from. */
    public const PLATFORMS = ['android', 'ios', 'web'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a handset against a user, moving it if it was somebody else's.
     *
     * A phone gets handed on, or two people share a tablet. The push token
     * belongs to the *installation*, not the person, so registering one that
     * already exists has to reassign it rather than create a second row —
     * otherwise the previous owner keeps receiving the new owner's leave
     * approvals and shift changes.
     */
    public static function register(User $user, array $attributes): self
    {
        $device = static::firstOrNew(['token' => $attributes['token']]);

        $device->fill($attributes);
        $device->user_id = $user->id;
        $device->last_seen_at = now();
        $device->save();

        return $device;
    }

    /** Devices that have not checked in for a while, for later pruning. */
    public function scopeStale($query, int $days = 90)
    {
        return $query->where('last_seen_at', '<', now()->subDays($days));
    }
}
