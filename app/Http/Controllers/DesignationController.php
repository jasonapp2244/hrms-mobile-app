<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index()
    {
        $companyId = $this->companyId();
        $designations = Designation::with('department')->withCount('employees')
            ->where('company_id', $companyId)->latest()->paginate(15);
        $departments = Department::where('company_id', $companyId)->get();

        return view('designations.index', compact('designations', 'departments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'department_id' => 'nullable|exists:departments,id',
        ]);
        $data['company_id'] = $this->companyId();
        Designation::create($data);

        return back()->with('success', 'Designation created.');
    }

    public function update(Request $request, Designation $designation)
    {
        abort_unless($designation->company_id === $this->companyId(), 403);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'department_id' => 'nullable|exists:departments,id',
            'is_active' => 'nullable|boolean',
        ]);
        $designation->update($data);

        return back()->with('success', 'Designation updated.');
    }

    public function destroy(Designation $designation)
    {
        abort_unless($designation->company_id === $this->companyId(), 403);
        $designation->delete();

        return back()->with('success', 'Designation deleted.');
    }
}
