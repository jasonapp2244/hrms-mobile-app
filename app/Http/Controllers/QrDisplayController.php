<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Office;
use App\Models\QrDisplay;
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
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'office_id' => 'required|integer',
            'name'      => 'required|string|max:100',
        ]);

        $office = Office::where('company_id', $companyId)->where('is_active', true)->find($data['office_id']);

        if (! $office) {
            return back()->withInput()->with('error', 'Choose one of your active offices.');
        }

        $display = QrDisplay::create([
            'company_id'         => $companyId,
            'office_id'          => $office->id,
            'name'               => $data['name'],
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
