<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * One line in the security trail (A1.8). Written once, never changed.
 */
class ActivityLog extends Model
{
    /** Authentication. */
    public const LOGIN            = 'login';
    public const LOGIN_FAILED     = 'login_failed';
    public const LOGOUT           = 'logout';
    public const LOCKOUT          = 'lockout';
    public const SESSION_EXPIRED  = 'session_expired';

    /** Credentials. */
    public const PASSWORD_CHANGED = 'password_changed';
    public const PASSWORD_RESET   = 'password_reset';

    /** Administration — the changes worth being able to point at afterwards. */
    public const ROLE_CHANGED     = 'role_changed';
    public const PERMISSION_CHANGED = 'permission_changed';
    public const SETTINGS_CHANGED = 'settings_changed';
    public const ACCOUNT_CHANGED  = 'account_changed';

    /** Label and badge colour per event, for the screen. */
    public const EVENTS = [
        self::LOGIN              => ['label' => 'Signed in',            'class' => 'success'],
        self::LOGIN_FAILED       => ['label' => 'Failed sign-in',       'class' => 'danger'],
        self::LOGOUT             => ['label' => 'Signed out',           'class' => 'secondary'],
        self::LOCKOUT            => ['label' => 'Locked out',           'class' => 'danger'],
        self::SESSION_EXPIRED    => ['label' => 'Session timed out',    'class' => 'secondary'],
        self::PASSWORD_CHANGED   => ['label' => 'Password changed',     'class' => 'warning'],
        self::PASSWORD_RESET     => ['label' => 'Password reset',       'class' => 'warning'],
        self::ROLE_CHANGED       => ['label' => 'Role changed',         'class' => 'warning'],
        self::PERMISSION_CHANGED => ['label' => 'Permissions changed',  'class' => 'warning'],
        self::SETTINGS_CHANGED   => ['label' => 'Settings changed',     'class' => 'info'],
        self::ACCOUNT_CHANGED    => ['label' => 'Account changed',      'class' => 'info'],
    ];

    /** Audit rows record when they happened, never when they were last touched. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'actor_label', 'event', 'description',
        'subject_type', 'subject_id', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Same rule the attendance trail keeps: a log that can be rewritten is not
     * evidence. Direct SQL can still reach it — that is what database
     * permissions are for — but nothing here can do it by accident.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Activity log entries are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Activity log entries are immutable and cannot be deleted.');
        });
    }

    /**
     * Record something.
     *
     * Takes the request rather than reaching for the facades so the caller can
     * pass the one it has — a listener fired during authentication does not
     * always see the same request instance a controller does, and the address
     * is the field most worth getting right.
     *
     * Never throws. A trail that can take the application down with it when the
     * table is missing or the disk is full turns an audit feature into an
     * outage, and the thing being logged has usually already happened.
     */
    public static function record(
        string $event,
        ?string $description = null,
        ?User $actor = null,
        ?Model $subject = null,
        ?Request $request = null,
        ?string $actorLabel = null,
        ?int $companyId = null,
    ): ?self {
        try {
            $actor ??= auth()->user();
            $request ??= request();

            return static::create([
                'company_id'   => $companyId ?? $actor?->company_id,
                'user_id'      => $actor?->id,
                'actor_label'  => $actorLabel ?? $actor?->name ?? $actor?->email,
                'event'        => $event,
                'description'  => $description,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id'   => $subject?->getKey(),
                'ip_address'   => $request?->ip(),
                // Truncated rather than dropped: browser strings run long and a
                // 500-character column would otherwise refuse the whole row.
                'user_agent'   => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getEventLabelAttribute(): string
    {
        return self::EVENTS[$this->event]['label'] ?? $this->event;
    }

    public function getEventClassAttribute(): string
    {
        return self::EVENTS[$this->event]['class'] ?? 'secondary';
    }

    /** Sign-in, sign-out and the failures around them. */
    public function scopeAuthentication($query)
    {
        return $query->whereIn('event', [
            self::LOGIN, self::LOGIN_FAILED, self::LOGOUT,
            self::LOCKOUT, self::SESSION_EXPIRED,
        ]);
    }
}
