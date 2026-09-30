<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The one-time sign-in code in an employee's welcome email (A4.21).
 *
 * It signs a phone in and does nothing else. It cannot punch: a code that sits
 * in an inbox can be forwarded, and the office screen exists precisely so that
 * the thing proving somebody is at work cannot be.
 */
class ActivationCode extends Model
{
    /** How long a welcome email stays usable. A week covers a weekend start. */
    public const VALID_DAYS = 7;

    /** What the QR carries, so the app can tell this code from an office one. */
    public const PREFIX = 'KEMP1-ACT:';

    protected $fillable = [
        'company_id', 'user_id', 'code_hash', 'expires_at', 'used_at', 'created_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * A fresh code for this account, returned in plain text exactly once.
     *
     * Any code sent before it stops working: "resend" means the old email is
     * the wrong one now, and two live codes would be two ways in.
     */
    public static function issueFor(User $user, ?User $by = null): string
    {
        static::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $code = Str::random(40);

        static::create([
            'company_id'         => $user->company_id,
            'user_id'            => $user->id,
            'code_hash'          => static::hash($code),
            'expires_at'         => now()->addDays(self::VALID_DAYS),
            'created_by_user_id' => $by?->id,
        ]);

        return $code;
    }

    /** The code out of a scanned QR, or the value itself if it came bare. */
    public static function parse(string $scanned): string
    {
        $scanned = trim($scanned);

        return str_starts_with($scanned, self::PREFIX)
            ? substr($scanned, strlen(self::PREFIX))
            : $scanned;
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
