<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Office;
use App\Models\QrDisplay;
use App\Support\QrLaunch;
use Illuminate\Http\Request;

/**
 * The office screens that show the check-in code (A4.21).
 *
 * Behind `manage-attendance`, not a new permission and not `manage-offices`:
 * HR runs attendance day to day and is the person asked to "put the code back
 * up on the tablet", while offices are admin-only company configuration.
 */
class QrDisplayController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $displays = QrDisplay::with(['office', 'createdBy'])
            ->where('company_id', $companyId)
            ->when(! $request->boolean('show_revoked'), fn ($q) => $q->active())
            ->orderByDesc('created_at')
            ->paginate($this->perPage('qr_displays'))
            ->withQueryString();

        $company = auth()->user()->company;

        return view('attendance.qr-displays', [
            'displays' => $displays,
            'offices'  => Office::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'required' => (bool) $company?->policy('require_qr_checkin'),
            'qrLaunch' => QrLaunch::forCompany($companyId),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'office_id' => 'required|integer',
            'name'      => 'nullable|string|max:100',
        ]);

        $office = Office::where('company_id', $companyId)->where('is_active', true)->find($data['office_id']);

        if (! $office) {
            return back()->withInput()->with('error', 'Choose one of your active offices.');
        }

        $display = QrDisplay::create([
            'company_id'         => $companyId,
            'office_id'          => $office->id,
            'name'               => filled($data['name'] ?? null) ? $data['name'] : QrLaunch::defaultName($office),
            'created_by_user_id' => auth()->id(),
        ]);

        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: sprintf('Set up the QR check-in screen "%s" for %s', $display->name, $office->name),
        );

        return back()->with('success', sprintf(
            'Screen "%s" is ready. Open its link on the device at %s and leave it on.',
            $display->name,
            $office->name,
        ));
    }

    /**
     * Pick an office, open its screen (A4.21) — the one-step version of
     * setting a screen up.
     *
     * Find-or-create rather than create: the office's existing screen is
     * reused, so the link a tablet already has bookmarked keeps working and
     * every click does not leave another screen behind. A switched-off screen
     * is never reused — that is the point of switching it off — so the next
     * open after a revoke makes a new one with a new link.
     *
     * POSTed from a form aimed at a new tab, because the first open writes a
     * row; the response is a redirect to the screen's signed link.
     */
    public function launch(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate(['office_id' => 'required|integer']);

        $office = Office::where('company_id', $companyId)->where('is_active', true)->find($data['office_id']);

        if (! $office) {
            return redirect()->route('attendance.qr-displays.index')
                ->with('error', 'Choose one of your active offices.');
        }

        $display = QrLaunch::screenFor($companyId, $office->id);

        if (! $display) {
            $display = QrDisplay::create([
                'company_id'         => $companyId,
                'office_id'          => $office->id,
                'name'               => QrLaunch::defaultName($office),
                'created_by_user_id' => auth()->id(),
            ]);

            ActivityLog::record(
                event: ActivityLog::SETTINGS_CHANGED,
                description: sprintf('Set up the QR check-in screen "%s" for %s', $display->name, $office->name),
            );
        }

        return redirect()->away($display->url());
    }

    /**
     * Stop a screen working — a tablet that walked off, or a link that was
     * shared where it should not have been. The codes it already accepted keep
     * pointing at it.
     */
    public function revoke(QrDisplay $display)
    {
        abort_unless($display->company_id === $this->companyId(), 403);

        if ($display->isRevoked()) {
            return back()->with('error', 'That screen has already been switched off.');
        }

        $display->revoke(auth()->user());

        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: sprintf('Switched off the QR check-in screen "%s"', $display->name),
        );

        return back()->with('success', sprintf('Screen "%s" is switched off. Its link no longer works.', $display->name));
    }
}
