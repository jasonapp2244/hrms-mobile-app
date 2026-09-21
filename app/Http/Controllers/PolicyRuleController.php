<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\PolicyRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The conditional rule builder (A2.9, A6.6).
 *
 * Everything on the Policies screen next door is one value for the whole
 * company — a grace window, a weekend, a switch. None of them can say "except
 * in the Croydon depot", or "and tell their manager when it happens". This is
 * the screen that can, and it is what lets a client add one without a
 * deployment.
 *
 * **It lives behind `manage-settings`, beside the policies themselves, and
 * gets no permission of its own.** A rule decides who the system speaks to
 * about everybody's attendance; that is the same decision the rest of that
 * screen makes, held by the same person. A separate `manage-rules` would put a
 * second name on the same authority and give the roles matrix a row nobody
 * could explain.
 *
 * The vocabulary — what a rule may ask about, which operators each field type
 * allows, what it may do — lives in `PolicyRule`, and is read here rather than
 * restated. Validation that repeated it would be a second copy free to drift
 * from the engine's, and the engine is the half that decides whether anybody
 * is notified.
 */
class PolicyRuleController extends Controller
{
    public function index()
    {
        $companyId = $this->companyId();

        $rules = PolicyRule::with('author')
            ->where('company_id', $companyId)
            ->orderBy('trigger')
            ->orderBy('name')
            ->paginate($this->perPage());

        return view('rules.index', [
            'rules'    => $rules,
            'triggers' => PolicyRule::TRIGGERS,
            'lookups'  => $this->lookups($companyId),
        ]);
    }

    public function create()
    {
        return view('rules.form', $this->formData(new PolicyRule([
            'trigger'   => PolicyRule::TRIGGER_PUNCH,
            'is_active' => true,
        ])));
    }

    public function edit(PolicyRule $rule)
    {
        abort_unless($rule->company_id === $this->companyId(), 403);

        return view('rules.form', $this->formData($rule));
    }

    public function store(Request $request)
    {
        $data = $this->validateRule($request);

        PolicyRule::create($data + [
            'company_id'         => $this->companyId(),
            'created_by_user_id' => auth()->id(),
        ]);

        $this->trail('Policy rule created: ' . $data['name']);

        return redirect()->route('rules.index')->with('success', 'Rule created.');
    }

    public function update(Request $request, PolicyRule $rule)
    {
        abort_unless($rule->company_id === $this->companyId(), 403);

        $rule->update($this->validateRule($request, $rule));

        $this->trail('Policy rule updated: ' . $rule->name);

        return redirect()->route('rules.index')->with('success', 'Rule updated.');
    }

    /**
     * Switch a rule off, or back on.
     *
     * Its own action rather than a tick on the form, because switching a noisy
     * rule off is the thing somebody does in a hurry, and making them open a
     * form and scroll past the conditions to do it is how a rule that should
     * have been paused gets deleted instead.
     */
    public function toggle(PolicyRule $rule)
    {
        abort_unless($rule->company_id === $this->companyId(), 403);

        $rule->update(['is_active' => ! $rule->is_active]);

        $this->trail(($rule->is_active ? 'Policy rule switched on: ' : 'Policy rule switched off: ') . $rule->name);

        return back()->with('success', $rule->is_active
            ? $rule->name . ' is on.'
            : $rule->name . ' is off. Its wording is kept, so it can be switched back on.');
    }

    public function destroy(PolicyRule $rule)
    {
        abort_unless($rule->company_id === $this->companyId(), 403);

        $name = $rule->name;
        $rule->delete();

        $this->trail('Policy rule deleted: ' . $name);

        return back()->with('success', 'Rule deleted.');
    }

    /**
     * Everything the form needs to draw itself, for both create and edit.
     *
     * The vocabulary goes to the browser as JSON as well as to Blade: the field
     * list changes with the trigger and the operator list changes with the
     * field, and a round trip to the server for each would make the form
     * unusable. It is the same constant either way, so the two cannot disagree.
     *
     * @return array<string, mixed>
     */
    protected function formData(PolicyRule $rule): array
    {
        return [
            'rule'      => $rule,
            'triggers'  => PolicyRule::TRIGGERS,
            'fields'    => PolicyRule::FIELDS,
            'operators' => PolicyRule::OPERATORS,
            'actions'   => PolicyRule::ACTIONS,
            'roles'     => PolicyRule::NOTIFIABLE_ROLES,
            'lookups'   => $this->lookups($this->companyId()),
        ];
    }

    /**
     * The row ids a `lookup` field may name, per lookup, for this company.
     *
     * Company-scoped for the reason every other listing is: a rule is one
     * client's, and a department picker offering another client's departments
     * would be a disclosure as well as a bug.
     *
     * @return array<string, array<int, string>>
     */
    protected function lookups(int $companyId): array
    {
        return [
            'departments' => Department::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all(),
            'offices'     => Office::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all(),
            'leave_types' => LeaveType::where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /**
     * Validate a posted rule against the declared vocabulary.
     *
     * The shape is checked by the validator and the *contents* by hand
     * underneath, because what a field may be compared with depends on which
     * field it is — a rule the validator's own syntax cannot state without a
     * closure per row that would be harder to read than this is.
     *
     * **Empty rows are dropped rather than refused.** The form always offers a
     * blank condition line so a rule can grow one; posting it untouched means
     * "no more conditions", not a mistake worth an error message.
     *
     * @return array<string, mixed>
     */
    protected function validateRule(Request $request, ?PolicyRule $rule = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                // Unique per company, not globally: two clients may both want
                // a rule called "Late arrivals", and the name is what somebody
                // recognises their own by in the list.
                Rule::unique('policy_rules')
                    ->where(fn ($q) => $q->where('company_id', $this->companyId()))
                    ->ignore($rule?->id),
            ],
            'trigger'      => ['required', Rule::in(array_keys(PolicyRule::TRIGGERS))],
            'conditions'   => 'nullable|array|max:10',
            'conditions.*' => 'array',
            'actions'      => 'nullable|array|max:10',
            'actions.*'    => 'array',
        ], [
            'name.unique' => 'A rule with this name already exists.',
        ]);

        return [
            'name'       => $data['name'],
            'trigger'    => $data['trigger'],
            'conditions' => $this->cleanConditions($request->input('conditions', []), $data['trigger']),
            'actions'    => $this->cleanActions($request->input('actions', [])),
            'is_active'  => $request->boolean('is_active'),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function cleanConditions(array $rows, string $trigger): array
    {
        $fields = PolicyRule::FIELDS[$trigger] ?? [];
        $lookups = $this->lookups($this->companyId());
        $clean = [];

        foreach ($rows as $index => $row) {
            $name = is_array($row) ? ($row['field'] ?? null) : null;

            // A blank line the form offered and nobody filled in.
            if (! $name) {
                continue;
            }

            $field = $fields[$name] ?? null;

            if (! $field) {
                throw ValidationException::withMessages([
                    "conditions.$index.field" => 'That is not something this trigger knows about.',
                ]);
            }

            $operator = $row['operator'] ?? null;

            if (! isset(PolicyRule::OPERATORS[$operator])
                || ! in_array($field['type'], PolicyRule::OPERATORS[$operator]['types'], true)) {
                throw ValidationException::withMessages([
                    "conditions.$index.operator" => sprintf(
                        '%s cannot be compared that way.',
                        $field['label'],
                    ),
                ]);
            }

            $value = $row['value'] ?? null;

            if ($value === null || $value === '') {
                throw ValidationException::withMessages([
                    "conditions.$index.value" => sprintf('Say what %s should be.', strtolower($field['label'])),
                ]);
            }

            // The value has to be one of the things that field can hold. A
            // choice outside its list, or a department id belonging to another
            // company, would be a rule that never fires or one that reads
            // somebody else's data — both silent, so both are refused here
            // rather than stored.
            $valid = match ($field['type']) {
                'choice' => array_key_exists($value, $field['options']),
                'lookup' => array_key_exists((int) $value, $lookups[$field['lookup']] ?? []),
                'number' => is_numeric($value),
                default  => false,
            };

            if (! $valid) {
                throw ValidationException::withMessages([
                    "conditions.$index.value" => $field['type'] === 'number'
                        ? sprintf('%s has to be a number.', $field['label'])
                        : sprintf('That is not a %s this company has.', strtolower($field['label'])),
                ]);
            }

            $clean[] = [
                'field'    => $name,
                'operator' => $operator,
                // Numbers stored as numbers, ids as ids. The engine compares
                // loosely so a string would work either way, but a rule read
                // back out of the column should say 20 rather than "20".
                'value'    => match ($field['type']) {
                    'number' => 0 + $value,
                    'lookup' => (int) $value,
                    default  => (string) $value,
                },
            ];
        }

        return $clean;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function cleanActions(array $rows): array
    {
        $clean = [];

        foreach ($rows as $index => $row) {
            $type = is_array($row) ? ($row['type'] ?? null) : null;

            if (! $type) {
                continue;
            }

            if (! isset(PolicyRule::ACTIONS[$type])) {
                throw ValidationException::withMessages([
                    "actions.$index.type" => 'That is not something a rule can do.',
                ]);
            }

            $action = ['type' => $type];

            if ($type === PolicyRule::ACTION_NOTIFY_ROLE) {
                $role = $row['role'] ?? null;

                if (! isset(PolicyRule::NOTIFIABLE_ROLES[$role])) {
                    throw ValidationException::withMessages([
                        "actions.$index.role" => 'Choose which role to notify.',
                    ]);
                }

                $action['role'] = $role;
            }

            $clean[] = $action;
        }

        // A rule that does nothing is not a rule. It would still match, still
        // stamp last_fired_at, and still tell nobody — which reads on the list
        // exactly like a rule that is working.
        if (! $clean) {
            throw ValidationException::withMessages([
                'actions' => 'A rule has to do something — notify somebody, or record it on the activity trail.',
            ]);
        }

        return $clean;
    }

    /**
     * The security trail entry for an edit to the rules themselves.
     *
     * `settings_changed` rather than `rule_fired`: this is somebody changing
     * how the system behaves, which is the question that event answers.
     * `rule_fired` is the rule doing its job afterwards, and conflating the two
     * would leave the trail unable to tell "an administrator wrote a rule"
     * from "the rule caught somebody".
     */
    protected function trail(string $description): void
    {
        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: $description,
        );
    }
}
