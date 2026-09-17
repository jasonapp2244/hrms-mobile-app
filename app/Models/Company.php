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
        // How long before a shift starts to remind somebody to clock in
        // (B5.1). Zero switches it off, which is the one thing a company might
        // reasonably want: this is the only notification here that arrives
        // *before* the working day, on a personal phone, at whatever hour the
        // early shift begins.
        //
        // **Must be at least as long as the gap between scheduler runs**, or
        // the whole window can fall between two of them and nobody is ever
        // reminded. `routes/console.php` runs it every five minutes and
        // `PolicyController` refuses anything between 1 and 4 for that reason.
        'checkin_reminder_before_minutes' => 10,

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

        // Bind an account to the first handset it signs in from (B1.6). Off by
        // default, like everything else that can refuse somebody: this one can
        // refuse a *sign-in*, which is the sharpest thing on this list, and the
        // company that wants it should switch it on knowing that a lost or
        // wiped phone then becomes an HR call. Turning it on locks nobody out
        // on the day — each account claims its own handset at its next sign-in,
        // and the control bites from the second one.
        'enforce_device_binding' => false,

        // Show colleagues' email and phone in the app's directory (B3.8). Off
        // by default, and this one matters more than it looks: `employees.phone`
        // is the only phone column on the record, and for a workforce with no
        // desk lines it holds personal mobiles. Defaulting this on would publish
        // every cleaner's mobile number to every other cleaner the moment the
        // app updated — a disclosure nobody consented to and one that cannot be
        // taken back. The directory still lists who works here and where; the
        // switch only governs how to reach them.
        'directory_show_contact_details' => false,
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
