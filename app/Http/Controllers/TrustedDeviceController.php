<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;

/**
 * The phones staff are signed in on, and the one button that unsticks them
 * (B1.6).
 *
 * **This screen is what makes the policy safe to switch on.** Binding an
 * account to a handset creates exactly one new way to be locked out — a lost,
 * broken, wiped or replaced phone — and a control with no release is a control
 * somebody disables permanently the first Monday it bites. Releasing is the
 * same call HR already takes for a forgotten password.
 *
 * Behind `manage-employees`, not a new permission: whoever can create somebody's
 * sign-in account is already the person who answers "I have a new phone".
 */
class TrustedDeviceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $devices = TrustedDevice::with(['user', 'releasedBy'])
            ->where('company_id', $companyId)
            ->when($request->filled('q'), fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$request->q}%")
                ->orWhere('email', 'like', "%{$request->q}%")))
            // Released rows are history and are hidden unless asked for — the
            // question this screen answers is "who is bound to what now".
            ->when(! $request->boolean('show_released'), fn ($q) => $q->active())
            ->orderByDesc('last_seen_at')
            ->paginate(25)
            ->withQueryString();

        return view('devices.index', [
            'devices' => $devices,
            'active'  => TrustedDevice::where('company_id', $companyId)->active()->count(),
        ]);
    }

    /**
     * Let this account be claimed by a different handset.
     *
     * The row is kept and stamped rather than deleted: who was trusted, and
     * when that stopped, is exactly what somebody asks after a dispute about a
     * punch, and a deleted row answers neither.
     */
    public function release(TrustedDevice $trustedDevice)
    {
        // The route takes a bound model, which is the leak. Company first,
        // every time.
        abort_unless($trustedDevice->company_id === $this->companyId(), 403);

        if ($trustedDevice->isReleased()) {
            return back()->with('error', 'That phone has already been released.');
        }

        $trustedDevice->release(auth()->user());

        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: sprintf(
                'Released the trusted phone (%s) for %s — their next sign-in claims a new one',
                $trustedDevice->device_name ?? 'unnamed device',
                $trustedDevice->user?->email ?? 'a deleted account',
            ),
            subject: $trustedDevice->user,
        );

        return back()->with('success', sprintf(
            '%s can now sign in on a new phone.',
            $trustedDevice->user?->name ?? 'That account',
        ));
    }
}
