<?php

namespace App\Http\Controllers;

use App\Models\CrashReport;
use App\Models\Office;
use Illuminate\Http\Request;

/**
 * Crashes the mobile app did not survive (B6.5).
 *
 * Grouped by fingerprint by default, because the useful question is "what is
 * broken" rather than "what happened at 14:12". A hundred handsets hitting one
 * bug is one row with a count; opening it lists the individual reports.
 *
 * Gated on manage-settings alongside the activity log — same audience, and a
 * stack trace names the internals of the system rather than anybody's
 * attendance.
 *
 * Read-only, with one exception: old reports can be cleared. Unlike the audit
 * trails this sits next to, a crash report is diagnosis and not evidence — once
 * a bug is fixed its reports are noise, and a screen nobody can tidy is a
 * screen nobody reads.
 */
class CrashReportController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->companyId();

        // Reports from before sign-in carry no company, and they are the ones
        // worth reading — the app failing to open is its worst failure. Same
        // reasoning as the failed sign-ins on the activity log.
        $scope = fn ($q) => $q
            ->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id'));

        $fingerprint = $request->query('fingerprint');

        if (is_string($fingerprint) && $fingerprint !== '') {
            return view('crashes.show', [
                'fingerprint' => $fingerprint,
                'reports'     => CrashReport::with('user')
                    ->where($scope)
                    ->where('fingerprint', $fingerprint)
                    ->latest('occurred_at')
                    ->paginate($this->perPage('crash_reports'))
                    ->withQueryString(),
            ]);
        }

        $groups = CrashReport::query()
            ->where($scope)
            ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->platform))
            ->selectRaw('fingerprint, exception, MAX(message) as message, COUNT(*) as hits, '
                . 'COUNT(DISTINCT app_version) as versions, MAX(app_version) as newest_version, '
                . 'MAX(occurred_at) as last_seen, MIN(occurred_at) as first_seen')
            ->groupBy('fingerprint', 'exception')
            ->orderByDesc('last_seen')
            ->paginate($this->perPage('crash_groups'))
            ->withQueryString();

        return view('crashes.index', [
            'groups'    => $groups,
            'platforms' => CrashReport::query()
                ->whereNotNull('platform')
                ->distinct()
                ->orderBy('platform')
                ->pluck('platform'),
            'total' => CrashReport::where($scope)->count(),
        ]);
    }

    /**
     * Clears reports older than a cutoff, or everything under one fingerprint.
     *
     * Deliberately not a per-row delete. Reports are read in groups, and
     * removing one of two hundred identical ones achieves nothing but a
     * misleading count.
     */
    public function destroy(Request $request)
    {
        $data = $request->validate([
            'fingerprint' => ['nullable', 'string', 'size:40'],
            'days'        => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $fingerprint = $data['fingerprint'] ?? null;
        $days        = $data['days'] ?? null;

        $query = CrashReport::query();

        if ($fingerprint !== null) {
            $query->where('fingerprint', $fingerprint);
        } elseif ($days !== null) {
            $query->where('created_at', '<', now()->subDays($days));
        } else {
            // Neither given would be "delete everything", which is not a thing
            // a stray form post should be able to do.
            return back()->with('error', 'Nothing was cleared: say which reports.');
        }

        $cleared = $query->delete();

        return redirect()
            ->route('crashes.index')
            ->with('status', $cleared === 1
                ? '1 crash report cleared.'
                : "{$cleared} crash reports cleared.");
    }
}
