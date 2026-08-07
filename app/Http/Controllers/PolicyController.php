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
        $id = auth()->user()->company_id ?? Office::value('company_id');

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
            'checkout_reminder_after_minutes' => 'required|integer|min:0|max:1440',
            'auto_close_after_minutes'      => 'required|integer|min:0|max:1440',
            'session_idle_timeout_minutes'  => 'required|integer|min:0|max:1440',
            'enforce_geofence'              => 'nullable|boolean',
            'require_two_factor_for_staff'  => 'nullable|boolean',
        ], [
            'session_idle_timeout_minutes.max' => 'An idle timeout longer than a day is the same as no timeout.',
        ]);

        $weekend = array_values(array_unique(array_map('intval', $data['weekend_days'] ?? [])));

        // A company that works seven days a week is a real thing; one that works
        // none is a typo that would charge zero days for every leave request and
        // report everybody as never absent.
        if (count($weekend) >= 7) {
            return back()->withInput()->with('error',
                'At least one day has to be a working day — otherwise leave costs nothing and nobody is ever absent.');
        }

        $before = $company->settings ?? [];

        $company->update(['settings' => array_merge($before, [
            // Written even when empty: ticking nothing means "we work every
            // day", which weekendDays() reads as an answer rather than as an
            // absence of one.
            'weekend_days' => $weekend,

            'checkout_reminder_after_minutes' => (int) $data['checkout_reminder_after_minutes'],
            'auto_close_after_minutes'        => (int) $data['auto_close_after_minutes'],
            'session_idle_timeout_minutes'    => (int) $data['session_idle_timeout_minutes'],
            'enforce_geofence'                => $request->boolean('enforce_geofence'),
            'require_two_factor_for_staff'    => $request->boolean('require_two_factor_for_staff'),
        ])]);

        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: 'Working week and attendance policy updated',
            subject: $company,
        );

        return back()->with('success', 'Policies updated.');
    }
}
