<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklistItem;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Joining and leaving checklists (A3.12): the company's standard steps, and one
 * person's copy of them.
 *
 * Gated on manage-employees throughout. Ticking "building access revoked" is a
 * claim about the real world that somebody may later be asked to stand behind,
 * so it belongs with whoever owns the employee record.
 */
class ChecklistController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    // -------------------------------------------------------------------------
    // The company's standard steps
    // -------------------------------------------------------------------------

    public function templates(Request $request)
    {
        $companyId = $this->companyId();

        return view('checklists.templates', [
            'onboarding'  => ChecklistTemplate::where('company_id', $companyId)
                ->ofKind('onboarding')->orderBy('position')->orderBy('id')->get(),
            'offboarding' => ChecklistTemplate::where('company_id', $companyId)
                ->ofKind('offboarding')->orderBy('position')->orderBy('id')->get(),
        ]);
    }

    public function storeTemplate(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data['company_id'] = $this->companyId();

        // Appended rather than inserted at the top: a checklist is read in
        // order, and a new step landing first would reorder everybody's list.
        $data['position'] = ChecklistTemplate::where('company_id', $data['company_id'])
            ->where('kind', $data['kind'])->max('position') + 1;

        ChecklistTemplate::create($data);

        return back()->with('success', 'Step added.');
    }

    public function updateTemplate(Request $request, ChecklistTemplate $template)
    {
        abort_unless($template->company_id === $this->companyId(), 403);

        $template->update($this->validateTemplate($request));

        return back()->with('success', 'Step updated.');
    }

    public function destroyTemplate(ChecklistTemplate $template)
    {
        abort_unless($template->company_id === $this->companyId(), 403);

        // Lists already raised keep their copy of this step — the item carries
        // its own title, and the template id is nulled rather than cascaded.
        $template->delete();

        return back()->with('success', 'Step removed. Checklists already raised are unaffected.');
    }

    protected function validateTemplate(Request $request): array
    {
        return $request->validate([
            'kind'            => ['required', Rule::in(array_keys(ChecklistTemplate::KINDS))],
            'title'           => 'required|string|max:200',
            'description'     => 'nullable|string|max:500',
            'owner'           => 'nullable|string|max:100',
            'due_offset_days' => 'required|integer|between:-365,365',
            'is_active'       => 'nullable|boolean',
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }

    // -------------------------------------------------------------------------
    // One person's list
    // -------------------------------------------------------------------------

    public function forEmployee(Employee $employee)
    {
        abort_unless($employee->company_id === $this->companyId(), 403);

        return view('checklists.employee', [
            'employee' => $employee,
            'items'    => $employee->checklistItems()
                ->orderBy('kind')->orderBy('position')->orderBy('id')->get()
                ->groupBy('kind'),
            'templates' => ChecklistTemplate::where('company_id', $this->companyId())
                ->active()->get()->groupBy('kind'),
        ]);
    }

    /**
     * Raise a checklist for this person from the current templates.
     *
     * Copies each step rather than pointing at it, so editing the template next
     * year does not rewrite what this person was asked to do. Steps already on
     * their list are skipped, which makes the button safe to press twice and
     * lets a new step be added to a list already in progress.
     */
    public function generate(Request $request, Employee $employee)
    {
        abort_unless($employee->company_id === $this->companyId(), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ChecklistTemplate::KINDS))],
            // Onboarding counts from the hire date, offboarding from a leaving
            // date nothing else records — so it is asked for here.
            'anchor_date' => 'nullable|date',
        ]);

        // ?? as well as ?: — validate() omits a nullable key the caller did not
        // send at all, so reading it directly is an undefined-index error
        // rather than the intended fallback.
        $anchor = ($data['anchor_date'] ?? null)
            ? \Carbon\Carbon::parse($data['anchor_date'])
            : ($data['kind'] === 'onboarding' ? $employee->hire_date : null);

        $templates = ChecklistTemplate::where('company_id', $this->companyId())
            ->ofKind($data['kind'])->active()
            ->orderBy('position')->orderBy('id')
            ->get();

        if ($templates->isEmpty()) {
            return back()->with('error',
                'There are no ' . ChecklistTemplate::KINDS[$data['kind']] . ' steps set up yet. Add some first.');
        }

        $existing = $employee->checklistItems()->ofKind($data['kind'])
            ->pluck('checklist_template_id')->filter()->flip();

        $added = 0;

        foreach ($templates as $template) {
            if ($existing->has($template->id)) {
                continue;
            }

            $employee->checklistItems()->create([
                'company_id'            => $employee->company_id,
                'checklist_template_id' => $template->id,
                'kind'                  => $template->kind,
                'title'                 => $template->title,
                'description'           => $template->description,
                'owner'                 => $template->owner,
                // No anchor means no date rather than a wrong one. A step due
                // "3 days before" a leaving date nobody supplied is not due on
                // a date computed from today.
                'due_on'                => $anchor?->copy()->addDays($template->due_offset_days)->toDateString(),
                'position'              => $template->position,
            ]);

            $added++;
        }

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: sprintf('%s checklist raised for %s (%d step(s))',
                ChecklistTemplate::KINDS[$data['kind']], $employee->full_name, $added),
            subject: $employee,
        );

        return back()->with('success', $added > 0
            ? "{$added} step(s) added."
            : 'Nothing to add — every step is already on their list.');
    }

    /** Tick or untick one step. */
    public function toggle(Request $request, Employee $employee, EmployeeChecklistItem $item)
    {
        abort_unless($employee->company_id === $this->companyId(), 403);
        abort_unless($item->employee_id === $employee->id, 404);

        $request->validate(['note' => 'nullable|string|max:500']);

        if ($item->isDone()) {
            // Un-ticking is allowed — people tick the wrong row — but the fact
            // it happened goes in the trail, because "who marked the door card
            // revoked" is the question this feature exists to answer.
            $item->update(['completed_at' => null, 'completed_by_user_id' => null]);

            ActivityLog::record(
                event: ActivityLog::ACCOUNT_CHANGED,
                description: "Checklist step \"{$item->title}\" un-ticked for {$employee->full_name}",
                subject: $employee,
            );

            return back()->with('success', 'Step reopened.');
        }

        $item->update([
            'completed_at'         => now(),
            'completed_by_user_id' => auth()->id(),
            'note'                 => $request->input('note') ?: $item->note,
        ]);

        ActivityLog::record(
            event: ActivityLog::ACCOUNT_CHANGED,
            description: "Checklist step \"{$item->title}\" completed for {$employee->full_name}",
            subject: $employee,
        );

        return back()->with('success', 'Step ticked off.');
    }

    public function destroyItem(Employee $employee, EmployeeChecklistItem $item)
    {
        abort_unless($employee->company_id === $this->companyId(), 403);
        abort_unless($item->employee_id === $employee->id, 404);

        $item->delete();

        return back()->with('success', 'Step removed from this checklist.');
    }
}
