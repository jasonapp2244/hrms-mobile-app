<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Office;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * The company's working week and its attendance/security policies.
 *
 * These values already existed and already drove behaviour — the working week
 * decides what leave is charged for and what counts as absence, and the
 * reminder and auto-close windows run the nightly jobs. What was missing was
 * any way to see or change them without editing a JSON column by hand, which
 * meant every one of them was in practice frozen at its default.
 *
 * A2.8 asked for weekend configuration "per office". It is per company here,
 * which is where the value has always been read from: the leave calculator, the
 * roster and the absence count all ask the company what a working day is, and
 * splitting that per office would mean an employee's leave costing a different
 * number of days depending on which branch processed it. If a client genuinely
 * runs different weeks per branch, that is a change to the leave engine and not
 * to this form.
 */
class PolicyController extends Controller
{
    /** Day-of-week numbers as Carbon reports them, which is what is stored. */
    public const DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function __construct(protected LeaveService $leave) {}

    protected function company(): Company
    {
        $id = $this->companyId();

        return Company::findOrFail($id);
    }

    public function edit()
    {
        $company = $this->company();

        return view('policies.index', [
            'company'  => $company,
            'weekend'  => $this->leave->weekendDays($company),
            'days'     => self::DAYS,
        ]);
    }

    public function update(Request $request)
    {
        $company = $this->company();

        $data = $request->validate([
            'weekend_days'                  => 'nullable|array',
            'weekend_days.*'                => 'integer|between:0,6',
            // Zero is off. Anything from 1 to 4 would be a window shorter than
            // the gap between scheduler runs, so the reminder would land
            // sometimes and not others — refused rather than accepted and
            // quietly unreliable. See routes/console.php.
            'checkin_reminder_before_minutes' => 'required|integer|min:0|max:120|not_in:1,2,3,4',
            'checkout_reminder_after_minutes' => 'required|integer|min:0|max:1440',
            'auto_close_after_minutes'      => 'required|integer|min:0|max:1440',
            'session_idle_timeout_minutes'  => 'required|integer|min:0|max:1440',
            'enforce_geofence'              => 'nullable|boolean',
            'enforce_device_binding'        => 'nullable|boolean',
            'require_two_factor_for_staff'  => 'nullable|boolean',
            'directory_show_contact_details' => 'nullable|boolean',
            // The day a punch is judged against when nobody rostered one
            // (A2.9). Both formats are accepted because a time input posts
            // H:i while the stored value carries seconds.
            'default_day_start'              => 'required|date_format:H:i,H:i:s',
            'default_day_end'                => 'required|date_format:H:i,H:i:s',
            // Capped at two hours, the same bound ShiftController puts on
            // late_grace_minutes: grace that outruns the day marks nobody
            // late, ever, which is a switched-off rule wearing a number.
            'default_day_grace_minutes'      => 'required|integer|min:0|max:120',
        ], [
            'session_idle_timeout_minutes.max' => 'An idle timeout longer than a day is the same as no timeout.',
            'checkin_reminder_before_minutes.not_in' => 'Use 0 to switch the reminder off, or at least 5 minutes — anything shorter can be missed entirely.',
        ]);

        $weekend = array_values(array_unique(array_map('intval', $data['weekend_days'] ?? [])));

        // A company that works seven days a week is a real thing; one that works
        // none is a typo that would charge zero days for every leave request and
        // report everybody as never absent.
        if (count($weekend) >= 7) {
            return back()->withInput()->with('error',
                'At least one day has to be a working day — otherwise leave costs nothing and nobody is ever absent.');
        }

        // Seconds normalised on the way in: a time input posts H:i and the
        // service compares the stored string against a full timestamp.
        $start = substr($data['default_day_start'], 0, 5) . ':00';
        $end   = substr($data['default_day_end'], 0, 5) . ':00';

        // A default day cannot run overnight. A rostered night shift can,
        // because its roster row says which calendar day it belongs to;
        // this one has no row, so determineStatus would measure an evening
        // arrival against tomorrow morning and call every night worker
        // early. Refused with a reason rather than stored and quietly wrong.
        if ($end <= $start) {
            return back()->withInput()->with('error',
                'The default day has to end after it starts. A night shift that runs past midnight needs a shift on the roster, which carries the date the hours belong to.');
        }

        $before = $company->settings ?? [];

        $company->update(['settings' => array_merge($before, [
            // Written even when empty: ticking nothing means "we work every
            // day", which weekendDays() reads as an answer rather than as an
            // absence of one.
            'weekend_days' => $weekend,

            'checkin_reminder_before_minutes' => (int) $data['checkin_reminder_before_minutes'],
            'checkout_reminder_after_minutes' => (int) $data['checkout_reminder_after_minutes'],
            'auto_close_after_minutes'        => (int) $data['auto_close_after_minutes'],
            'session_idle_timeout_minutes'    => (int) $data['session_idle_timeout_minutes'],
            'enforce_geofence'                => $request->boolean('enforce_geofence'),
            'enforce_device_binding'          => $request->boolean('enforce_device_binding'),
            'require_two_factor_for_staff'    => $request->boolean('require_two_factor_for_staff'),
            'directory_show_contact_details'  => $request->boolean('directory_show_contact_details'),
            'default_day_start'               => $start,
            'default_day_end'                 => $end,
            'default_day_grace_minutes'       => (int) $data['default_day_grace_minutes'],
        ])]);

        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: 'Working week and attendance policy updated',
            subject: $company,
        );

        return back()->with('success', 'Policies updated.');
    }
}
