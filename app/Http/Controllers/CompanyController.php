<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Office;
use App\Support\Timezones;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    /** The client pays in dollars; nothing else is offered. */
    public const CURRENCIES = ['USD' => 'US Dollar ($)'];

    /**
     * The currency choices, keeping a company's existing non-USD code so
     * saving the form for some other reason does not silently change it.
     *
     * @return array<string, string>
     */
    public static function currencies(?string $current = null): array
    {
        return $current && ! isset(self::CURRENCIES[$current])
            ? self::CURRENCIES + [$current => $current]
            : self::CURRENCIES;
    }

    protected function company(): Company
    {
        $id = $this->companyId();
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
            // US zones and dollars only; the company's current values are let
            // through so an older non-US setting can be saved unchanged.
            'timezone' => ['required', 'timezone', Rule::in([...array_keys(Timezones::US), $company->timezone])],
            'currency' => ['required', Rule::in(array_keys(self::currencies($company->currency)))],
        ]);
        $company->update($data);

        return back()->with('success', 'Company profile updated.');
    }
}
