<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\PasswordResetLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasApiTokens, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'company_id',
        'phone',
        'avatar',
        'is_active',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The employee record this user account belongs to (for employee-role logins). */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Handsets registered to receive push notifications. */
    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class);
    }

    /**
     * Route name of the home page this user should land on, based on role.
     *
     * Three destinations, one per area, and each one is the only area that role
     * can actually reach: admin and HR to the staff dashboard behind
     * `role:admin|hr`, managers to their own dashboard behind `role:manager`,
     * everybody else to the self-service portal.
     *
     * Order matters. An admin who also manages a team is an admin here — the
     * staff dashboard is the larger screen and their manager area stays one
     * click away, whereas the reverse would take an administrator to a page
     * showing four cleaners and no way back to the company.
     *
     * Checked before it is used, everywhere: sending somebody to a route their
     * middleware refuses is a redirect loop, which is how this went wrong the
     * first time — managers used to land on 'dashboard' and bounce.
     */
    public function homeRoute(): string
    {
        if ($this->hasAnyRole(['admin', 'hr'])) {
            return 'dashboard';
        }

        return $this->hasRole('manager')
            ? 'manager.dashboard'
            : 'employee.dashboard';
    }

    /**
     * Send the reset link through our own notification rather than Laravel's.
     *
     * Overriding here rather than swapping the class in a service provider is
     * what the framework expects, and it keeps the decision visible on the
     * model that receives it.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLink($token));
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // A serialised user goes to the mobile API, to log context and to
        // queued jobs. The secret is the whole of somebody's second factor and
        // belongs in none of those.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Without this the boolean column comes back as 1/0, so a strict
            // check against false never matched and a disabled account could
            // still sign in through the API.
            'is_active' => 'boolean',

            // Encrypted at rest (A1.7). A database dump on its own is then not
            // enough to generate anybody's codes — the app key is needed too,
            // and that does not live in the database.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',

            // Which dashboard panels this person keeps (A8.5). Null means never
            // chosen and falls back to the role default; [] means they turned
            // everything off, which is a different thing.
            'dashboard_widgets' => 'array',
        ];
    }

    /**
     * Is two-factor actually in force for this account?
     *
     * Confirmed, not merely started. Somebody halfway through setup — secret
     * generated, never verified — signs in exactly as before, which is what
     * stops a mistyped setup from locking them out.
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Does the company insist this account uses it?
     *
     * Applies to admin and HR only. They are the accounts that can read and
     * change everybody's records; an employee whose access is their own
     * attendance is not worth locking out of a phone-based clock-in over.
     */
    public function mustUseTwoFactor(): bool
    {
        return (bool) $this->company?->policy('require_two_factor_for_staff')
            && $this->hasAnyRole(['admin', 'hr']);
    }

    /** Fresh single-use recovery codes, replacing any that existed. */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = collect(range(1, $count))
            // Two groups so they are readable when written down, which is
            // exactly what people do with them.
            ->map(fn () => strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4))))
            ->all();

        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /**
     * Spend a recovery code, if it is one.
     *
     * Single use: the code is struck off before this returns, so the same slip
     * of paper cannot be used twice — which is the only thing that makes a
     * written-down code acceptable in the first place.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        $codes = $this->two_factor_recovery_codes ?? [];

        $match = null;

        foreach ($codes as $candidate) {
            if (hash_equals($candidate, $code)) {
                $match = $candidate;
                break;
            }
        }

        if ($match === null) {
            return false;
        }

        $this->forceFill([
            'two_factor_recovery_codes' => array_values(array_filter($codes, fn ($c) => $c !== $match)),
        ])->save();

        return true;
    }
}
