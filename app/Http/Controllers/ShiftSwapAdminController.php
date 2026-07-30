<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\ShiftSwapRequest;
use App\Services\RosterService;
use Illuminate\Http\Request;

/**
 * The company-wide swap register for Admin and HR.
 *
 * HR approves any swap in the company, where a manager is limited to their own
 * reports. Someone has to be able to sanction a swap between two people who
 * report to different managers, or across a team whose manager is away.
 */
class ShiftSwapAdminController extends Controller
{
    public function __construct(
        protected RosterService $roster,
    ) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $swaps = ShiftSwapRequest::with(['requester.department', 'target', 'approver'])
            ->where('company_id', $companyId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('requester_date')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'awaiting_colleague' => ShiftSwapRequest::where('company_id', $companyId)->where('status', 'pending')->count(),
            'awaiting_approval'  => ShiftSwapRequest::where('company_id', $companyId)->awaitingApproval()->count(),
            'approved'           => ShiftSwapRequest::where('company_id', $companyId)->where('status', 'approved')->count(),
        ];

        return view('shift-swaps.index', compact('swaps', 'stats'));
    }

    public function approve(Request $request, ShiftSwapRequest $swap)
    {
        $this->authoriseCompany($swap);
        $this->roster->approveSwap($swap, auth()->id(), $request->input('decision_note'));

        return back()->with('success', 'Swap approved — the roster has been updated.');
    }

    public function reject(Request $request, ShiftSwapRequest $swap)
    {
        $this->authoriseCompany($swap);

        $data = $request->validate(
            ['decision_note' => 'required|string|max:1000'],
            ['decision_note.required' => 'Please give a reason — both employees see this.'],
        );

        $this->roster->rejectSwap($swap, auth()->id(), $data['decision_note']);

        return back()->with('success', 'Swap rejected.');
    }

    protected function authoriseCompany(ShiftSwapRequest $swap): void
    {
        abort_unless($swap->company_id === $this->companyId(), 403);
    }
}
