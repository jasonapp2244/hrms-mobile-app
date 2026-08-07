<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'website', 'logo', 'address',
        'city', 'country', 'timezone', 'currency', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * A guaranteed-valid timezone identifier for date math. Falls back to the
     * app default if the stored value is empty or not a real timezone, so bad
     * data can never crash now()/Carbon::now() with an "Unknown timezone" error.
     */
    public function tz(): string
    {
        return ($this->timezone && in_array($this->timezone, timezone_identifiers_list(), true))
            ? $this->timezone
            : config('app.timezone');
    }

    /**
     * Attendance policy defaults, overridable per company via `settings`.
     *
     * Kept as settings rather than constants so a company can be corrected with
     * one row update instead of a deployment, and stated here rather than
     * scattered through the commands that read them.
     */
    public const POLICY_DEFAULTS = [
        // How long after a shift ends before somebody still clocked in is
        // nudged. Short enough to catch them before they get home.
        'checkout_reminder_after_minutes' => 30,

        // How long after a shift ends before the day is closed for them.
        // Generous — overtime is normal, and closing a day somebody is still
        // working would understate their hours.
        'auto_close_after_minutes' => 240,

        // Minutes of inactivity before somebody is signed out (A1.9). Zero is
        // off, and off is the default: an idle timeout is a policy a company
        // adopts, not one imposed on it, and imposing one silently would start
        // logging people out of a system that had never done that before.
        'session_idle_timeout_minutes' => 0,

        // Require admin and HR accounts to carry a second factor (A1.7). Off by
        // default: turning it on locks every such account out of the dashboard
        // until they have set an authenticator up, which is the right policy but
        // has to be somebody's decision and not a surprise after an update.
        'require_two_factor_for_staff' => false,

        // Refuse a punch made outside the office's geofence (A4.16). Off by
        // default and deliberately so — the client's whole premise is that
        // staff clock in from their own phone, including from home, and the
        // coordinates are a record rather than a gate. A company that wants
        // office-only attendance turns this on knowing what it costs.
        'enforce_geofence' => false,
    ];

    /** A policy value for this company, falling back to the default. */
    public function policy(string $key): mixed
    {
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === ''
            ? (self::POLICY_DEFAULTS[$key] ?? null)
            : $value;
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
