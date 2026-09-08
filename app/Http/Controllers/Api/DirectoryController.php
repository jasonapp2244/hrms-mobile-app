<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who else works here (B3.8).
 *
 * The one endpoint in the API that answers about **other people**, which is why
 * it is the most conservative. Everything else is scoped to the caller's own
 * record; this is scoped to their company, so what it returns is what every
 * employee may know about every colleague.
 *
 * What it will not return, deliberately: date of birth, home address, national
 * id, blood group, personal email, emergency contact, salary, employment status,
 * hire date, documents, attendance, leave — and the reporting line. Those are
 * HR-grade facts behind `manage-employees`, and CLAUDE.md already records that
 * even a *manager* never sees them for their own team. A colleague plainly
 * cannot see more than a manager does.
 *
 * Email and phone sit behind `directory_show_contact_details`, off by default.
 * There is a single phone column on an employee record, and for a workforce
 * with no desk lines it holds personal mobiles — publishing those to everybody
 * on an app update is a disclosure nobody consented to and one that cannot be
 * withdrawn. See Company::POLICY_DEFAULTS.
 */
class DirectoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee()->load('company');

        $data = $request->validate([
            'q'             => 'nullable|string|max:100',
            'department_id' => 'nullable|integer',
            'office_id'     => 'nullable|integer',
            'page'          => 'nullable|integer|min:1',
        ]);

        $showContact = (bool) $employee->company?->policy('directory_show_contact_details');

        $query = Employee::with(['department', 'designation', 'office'])
            // The caller's own company and nothing else. The schema is
            // company-scoped throughout, and this is the one place where
            // forgetting that would hand somebody another client's staff list.
            ->where('company_id', $employee->company_id)
            // Active only. A directory is for finding somebody who still works
            // here; leavers keep their record for the audit trail, not for this.
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if (! empty($data['q'])) {
            $term = '%' . $data['q'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term);
            });
        }

        // Filtered, not scoped: these narrow the same company-wide list, so an
        // id from another company matches nothing rather than reaching past the
        // where above.
        if (! empty($data['department_id'])) {
            $query->where('department_id', $data['department_id']);
        }

        if (! empty($data['office_id'])) {
            $query->where('office_id', $data['office_id']);
        }

        $page = $query->paginate(30);

        return $this->ok([
            'people' => collect($page->items())
                ->map(fn (Employee $person) => $this->payload($person, $showContact))
                ->values(),
            'meta' => $this->pageMeta($page),
            // Stated rather than left to be inferred from absent keys: the app
            // needs to know the difference between "this company does not share
            // contact details" and "this person has none on file", so it can
            // hide the call button entirely instead of showing a dead one.
            'shows_contact_details' => $showContact,
        ]);
    }

    protected function payload(Employee $person, bool $showContact): array
    {
        $payload = [
            'id'            => $person->id,
            'employee_code' => $person->employee_code,
            'full_name'     => $person->full_name,
            'designation'   => $person->designation?->name,
            'department'    => $person->department?->name,
            'office'        => $person->office?->name,
            // Whether to expect somebody at a desk is the question a directory
            // is opened to answer, and it is not a private fact.
            'work_mode' => $person->work_mode,
            'photo_url' => $person->photo_url,
        ];

        if (! $showContact) {
            return $payload;
        }

        return $payload + [
            'email' => $person->email,
            'phone' => $person->phone,
        ];
    }
}
