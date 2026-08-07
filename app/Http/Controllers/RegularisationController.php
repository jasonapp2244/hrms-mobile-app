<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRegularisation;
use App\Models\Office;
use App\Services\RegularisationService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * HR's queue of regularisation requests (A4.13).
 *
 * Gated on manage-attendance, the same permission A4.12 uses: approving one of
 * these writes a punch and may strike another out, which is exactly what keying
 * a correction in by hand does. Anyone allowed to do it one way should be
 * allowed to do it the other, and nobody else should be able to do either.
 */
class RegularisationController extends Controller
{
    public function __construct(
        protected RegularisationService $regularisation,
    ) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $requests = AttendanceRegularisation::with(['employee', 'attendanceLog', 'createdLog', 'decidedBy'])
            ->forCompany($companyId)
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->status),
                // Pending first by default: this is a queue, and a decided
                // request is history rather than work.
                fn ($q) => $q->pending(),
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $pendingCount = AttendanceRegularisation::forCompany($companyId)->pending()->count();

        return view('regularisations.index', compact('requests', 'pendingCount'));
    }

    public function approve(Request $request, AttendanceRegularisation $regularisation)
    {
        return $this->decide($request, $regularisation, 'approve');
    }

    public function reject(Request $request, AttendanceRegularisation $regularisation)
    {
        return $this->decide($request, $regularisation, 'reject');
    }

    /**
     * Both decisions share their guards, their validation and their failure
     * handling; only the verb differs. Two copies of this would eventually
     * disagree about which company checks apply.
     */
    protected function decide(Request $request, AttendanceRegularisation $regularisation, string $verb)
    {
        abort_unless($regularisation->company_id === $this->companyId(), 404);

        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->regularisation->{$verb}($regularisation, auth()->user(), $data['decision_note'] ?? null);
        } catch (RuntimeException $e) {
            // Raised when the request was already decided — a stale tab or a
            // double submit. A plain message beats a 500.
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', $verb === 'approve'
            ? 'Request approved. The attendance record has been corrected.'
            : 'Request rejected. Attendance is unchanged.');
    }
}
