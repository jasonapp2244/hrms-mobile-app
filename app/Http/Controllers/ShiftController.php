<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index()
    {
        $shifts = Shift::withCount('employees')
            ->where('company_id', $this->companyId())
            ->orderBy('start_time')
            ->paginate(15);

        return view('shifts.index', compact('shifts'));
    }

    public function store(Request $request)
    {
        $data = $this->validateShift($request);
        $data['company_id'] = $this->companyId();
        Shift::create($data);

        return back()->with('success', 'Shift created.');
    }

    public function update(Request $request, Shift $shift)
    {
        abort_unless($shift->company_id === $this->companyId(), 403);
        $shift->update($this->validateShift($request));

        return back()->with('success', 'Shift updated.');
    }

    public function destroy(Shift $shift)
    {
        abort_unless($shift->company_id === $this->companyId(), 403);
        $shift->delete();

        return back()->with('success', 'Shift deleted.');
    }

    protected function validateShift(Request $request): array
    {
        return $request->validate([
            'name'               => 'required|string|max:100',
            'code'               => 'nullable|string|max:30',
            'start_time'         => 'required|date_format:H:i',
            'end_time'           => 'required|date_format:H:i',
            'break_minutes'      => 'required|integer|min:0|max:480',
            'late_grace_minutes' => 'required|integer|min:0|max:120',
            'color'              => 'nullable|string|max:20',
            'is_active'          => 'nullable|boolean',
        ]);
    }
}
