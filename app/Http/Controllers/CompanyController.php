<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Office;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    protected function company(): Company
    {
        $id = auth()->user()->company_id ?? Office::value('company_id');
        return Company::findOrFail($id);
    }

    public function index()
    {
        $company = $this->company();
        return view('company.index', compact('company'));
    }

    public function update(Request $request)
    {
        $company = $this->company();
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'timezone' => 'required|string|max:64',
        ]);
        $company->update($data);

        return back()->with('success', 'Company profile updated.');
    }
}
