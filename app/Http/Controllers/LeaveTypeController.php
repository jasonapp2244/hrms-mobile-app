<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index()
    {
        $leaveTypes = LeaveType::withCount(['requests', 'balances'])
            ->where('company_id', $this->companyId())
            ->orderBy('name')
            ->paginate(15);

        return view('leave-types.index', compact('leaveTypes'));
    }

    public function store(Request $request)
    {
        $data = $this->validateType($request);
        $data['company_id'] = $this->companyId();
        LeaveType::create($data);

        return back()->with('success', 'Leave type created.');
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        abort_unless($leaveType->company_id === $this->companyId(), 403);
        $leaveType->update($this->validateType($request, $leaveType));

        return back()->with('success', 'Leave type updated.');
    }

    public function destroy(LeaveType $leaveType)
    {
        abort_unless($leaveType->company_id === $this->companyId(), 403);

        // The FK restricts on delete so history cannot be erased. Check first so
        // the user gets an explanation instead of a database error, and point
        // them at deactivation, which is what they almost always want.
        if ($leaveType->requests()->exists()) {
            return back()->with('error',
                "\"{$leaveType->name}\" cannot be deleted because leave has already been "
                . 'taken under it. Set it to Inactive instead — that stops new requests '
                . 'without touching the existing records.');
        }

        $leaveType->delete();

        return back()->with('success', 'Leave type deleted.');
    }

    protected function validateType(Request $request, ?LeaveType $leaveType = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                // Unique per company, not globally — two companies may both have
                // an "Annual Leave". Ignores itself so a rename-less edit passes.
                Rule::unique('leave_types')
                    ->where(fn ($q) => $q->where('company_id', $this->companyId()))
                    ->ignore($leaveType?->id),
            ],
            'code'              => 'nullable|string|max:30',
            'days_per_year'     => 'required|numeric|min:0|max:365',
            'accrual_mode'      => 'nullable|in:upfront,monthly',
            'carry_forward_max' => 'nullable|numeric|min:0|max:365',
            'color'             => 'nullable|string|max:20',
        ], [
            'name.unique' => 'A leave type with this name already exists.',
        ]);

        // Read the toggles off the request rather than the validated array: an
        // unchecked box submits nothing, and a missing key would leave the old
        // value in place on update instead of turning the flag off.
        foreach (['is_paid', 'requires_approval', 'allow_half_day', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        // An empty carry-forward box means "unlimited", which is stored as null.
        $data['carry_forward_max'] = $request->filled('carry_forward_max')
            ? $request->input('carry_forward_max')
            : null;

        return $data;
    }
}
