<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One crash the mobile app did not survive (B6.5).
 *
 * Written once and never changed, for the same reason the activity log is: a
 * record somebody can tidy up is not a record of what happened. It is the only
 * table in the system that is safe to *delete* from, though — a crash report
 * is diagnosis, not evidence, and old ones are noise.
 */
class CrashReport extends Model
{
    /** Diagnostics carry when they happened, not when they were last touched. */
    public const UPDATED_AT = null;

    /** As much of a stack as is worth keeping. Past this it is framework noise. */
    public const STACK_LIMIT = 8000;

    /** Frames that go into the fingerprint. Enough to tell two bugs apart. */
    protected const FINGERPRINT_FRAMES = 4;

    protected $fillable = [
        'company_id', 'user_id', 'platform', 'app_version', 'os_version',
        'exception', 'message', 'stack', 'fingerprint', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Crash reports are written once and cannot be modified.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One hash per distinct place a crash happens.
     *
     * The top few frames only. Including the whole stack would give every
     * handset its own fingerprint — the lower frames differ with how the app
     * was navigated to get there — and the screen would become a list of
     * individual incidents rather than of bugs.
     *
     * Line numbers are kept: two crashes in one method on different lines are
     * usually two bugs, and merging them hides one of them.
     */
    public static function fingerprintFor(string $exception, ?string $stack): string
    {
        $frames = collect(preg_split('/\R/', (string) $stack))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->take(self::FINGERPRINT_FRAMES)
            ->implode("\n");

        return sha1($exception . "\n" . $frames);
    }
}
