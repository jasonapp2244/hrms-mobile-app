<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\ShiftSwapRequest;
use App\Services\RosterService;
use Illuminate\Http\Request;

/**
 * Shift swaps in the self-service portal.
 *
 * Three people can act on a swap and each is scoped on the record rather than
 * by permission alone: the requester may withdraw their own, the colleague named
 * may accept or decline theirs, and a manager may sanction one belonging to
 * their own direct reports.
 */
class ShiftSwapController extends Controller
{
    public function __construct(
        protected RosterService $roster,
    ) {}

    protected function currentEmployee(): Employee
    {
        $employee = auth()->user()->employee;
        abort_unless($employee, 403, 'No employee record is linked to this account.');

        return $employee;
    }

    public function index()
    {
        $employee = $this->currentEmployee();

        $mine = ShiftSwapRequest::with(['target', 'approver'])
            ->where('requester_id', $employee->id)
            ->latest('requester_date')
            ->limit(20)
            ->get();

        $incoming = ShiftSwapRequest::with('requester')
            ->where('target_id', $employee->id)
            ->where('status', 'pending')
            ->orderBy('target_date')
            ->get();

        $history = ShiftSwapRequest::with(['requester', 'approver'])
            ->where('target_id', $employee->id)
            ->where('status', '!=', 'pending')
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // Colleagues to swap with, and the days this employee is rostered on.
        $colleagues = Employee::where('company_id', $employee->company_id)
            ->active()
            ->whereKeyNot($employee->id)
            ->orderBy('first_name')
            ->get();

        return view('employee.swaps', compact('employee', 'mine', 'incoming', 'history', 'colleagues'));
    }

    public function store(Request $request)
    {
        $employee = $this->currentEmployee();

        $data = $request->validate([
            'requester_date' => 'required|date',
            'target_id'      => 'required|integer|exists:employees,id',
            'target_date'    => 'required|date',
            'reason'         => 'nullable|string|max:1000',
        ]);

        $target = Employee::find($data['target_id']);
        abort_unless($target, 404);

        $this->roster->requestSwap(
            $employee,
            $data['requester_date'],
            $target,
            $data['target_date'],
            $data['reason'] ?? null,
        );

        return redirect()->route('employee.swaps.index')
            ->with('success', "Swap requested. {$target->first_name} has to accept it before a manager can approve.");
    }

    public function accept(Request $request, ShiftSwapRequest $swap)
    {
        $this->authoriseTarget($swap);
        $this->roster->acceptSwap($swap, $request->input('response_note'));

        return back()->with('success', 'Accepted. It now needs a manager to approve it.');
    }

    public function decline(Request $request, ShiftSwapRequest $swap)
    {
        $this->authoriseTarget($swap);
        $this->roster->declineSwap($swap, $request->input('response_note'));

        return back()->with('success', 'Swap declined.');
    }

    public function cancel(ShiftSwapRequest $swap)
    {
        abort_unless($swap->requester_id === $this->currentEmployee()->id, 403);

        $this->roster->cancelSwap($swap);

        return back()->with('success', 'Swap withdrawn.');
    }

    // ---- manager decisions ----

    public function approve(Request $request, ShiftSwapRequest $swap)
    {
        $this->authoriseManager($swap);
        $this->roster->approveSwap($swap, auth()->id(), $request->input('decision_note'));

        return back()->with('success', 'Swap approved — the roster has been updated.');
    }

    public function reject(Request $request, ShiftSwapRequest $swap)
    {
        $this->authoriseManager($swap);

        $data = $request->validate(
            ['decision_note' => 'required|string|max:1000'],
            ['decision_note.required' => 'Please give a reason — both employees see this.'],
        );

        $this->roster->rejectSwap($swap, auth()->id(), $data['decision_note']);

        return back()->with('success', 'Swap rejected.');
    }

    protected function authoriseTarget(ShiftSwapRequest $swap): void
    {
        abort_unless($swap->target_id === $this->currentEmployee()->id, 403);
    }

    /**
     * A manager may sanction a swap only where at least one side is their own
     * direct report, and never one they are personally part of — approving a
     * trade you are standing in is not an approval.
     */
    protected function authoriseManager(ShiftSwapRequest $swap): void
    {
        abort_unless(auth()->user()->can('approve-swaps'), 403);

        $manager = $this->currentEmployee();

        abort_if(
            in_array($manager->id, [$swap->requester_id, $swap->target_id], true),
            403,
            'You cannot approve a swap you are part of.',
        );

        $team = $manager->subordinates()->pluck('id');

        abort_unless(
            $team->contains($swap->requester_id) || $team->contains($swap->target_id),
            403,
        );
    }
}
