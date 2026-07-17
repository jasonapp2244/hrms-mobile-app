<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Office;
use App\Models\Shift;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index()
    {
        $departments = Department::withCount('employees')->with('shift')
            ->where('company_id', $this->companyId())
            ->latest()->paginate(15);

        $shifts = Shift::where('company_id', $this->companyId())->where('is_active', true)->get();

        return view('departments.index', compact('departments', 'shifts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:50',
            'shift_id' => 'nullable|exists:shifts,id',
            'description' => 'nullable|string',
        ]);
        $data['company_id'] = $this->companyId();
        Department::create($data);

        return back()->with('success', 'Department created.');
    }

    public function update(Request $request, Department $department)
    {
        abort_unless($department->company_id === $this->companyId(), 403);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:50',
            'shift_id' => 'nullable|exists:shifts,id',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $department->update($data);

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Department $department)
    {
        abort_unless($department->company_id === $this->companyId(), 403);
        $department->delete();

        return back()->with('success', 'Department deleted.');
    }
}
