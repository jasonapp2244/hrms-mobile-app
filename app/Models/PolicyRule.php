<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One if-this-then-that rule (A2.9, A6.6).
 *
 * The vocabulary lives here rather than in the controller or the engine,
 * because three things have to agree about it and only one copy can be right:
 * the form that offers the fields, the validator that accepts them, and the
 * engine that evaluates them. A field added below appears in all three.
 */
class PolicyRule extends Model
{
    protected $fillable = [
        'company_id', 'name', 'trigger', 'conditions', 'actions',
        'is_active', 'last_fired_at', 'created_by_user_id',
    ];

    protected $casts = [
        'conditions'    => 'array',
        'actions'       => 'array',
        'is_active'     => 'boolean',
        'last_fired_at' => 'datetime',
    ];

    /**
     * When a rule is evaluated.
     *
     * Both are moments the system already had to notice — a punch being written
     * and a request being raised — so a rule costs one extra query on a path
     * that was already writing a row. Nothing here runs on a schedule: a
     * nightly "who was late this week" rule would be a report, and there is a
     * whole reporting module for that.
     */
    public const TRIGGER_PUNCH = 'punch.recorded';
    public const TRIGGER_LEAVE = 'leave.requested';

    public const TRIGGERS = [
        self::TRIGGER_PUNCH => 'When somebody clocks in or out',
        self::TRIGGER_LEAVE => 'When somebody requests leave',
    ];

    /**
     * What a condition may ask about, per trigger.
     *
     * `type` drives both the form control and the operators offered:
     *  - `choice` — a fixed list, compared with is / is_not
     *  - `number` — compared with is / at_least / at_most
     *  - `lookup` — a row id resolved at render time (department, office,
     *    leave type), compared with is / is_not
     *
     * Anything not named here is refused on save **and skipped on read**. The
     * second half is the one that matters: a rule naming a leave type that was
     * deleted last month must not throw inside somebody's leave request.
     */
    public const FIELDS = [
        self::TRIGGER_PUNCH => [
            'status' => [
                'label'   => 'Punch status',
                'type'    => 'choice',
                'options' => [
                    'ontime'      => 'On time',
                    'late'        => 'Late',
                    'early_leave' => 'Early leave',
                ],
            ],
            'type' => [
                'label'   => 'Direction',
                'type'    => 'choice',
                'options' => ['in' => 'Clock in', 'out' => 'Clock out'],
            ],
            'minutes_late' => [
                'label' => 'Minutes late',
                'type'  => 'number',
            ],
            'source' => [
                'label'   => 'Recorded from',
                'type'    => 'choice',
                'options' => [
                    'mobile'         => 'The app',
                    'mobile_offline' => 'The app, offline',
                    'pwa'            => 'The browser',
                    'kiosk'          => 'A kiosk',
                    'manual'         => 'Entered by hand',
                ],
            ],
            'department_id' => ['label' => 'Department', 'type' => 'lookup', 'lookup' => 'departments'],
            'office_id'     => ['label' => 'Office',     'type' => 'lookup', 'lookup' => 'offices'],
        ],

        self::TRIGGER_LEAVE => [
            'leave_type_id' => ['label' => 'Leave type', 'type' => 'lookup', 'lookup' => 'leave_types'],
            'days' => [
                'label' => 'Days requested',
                'type'  => 'number',
            ],
            'notice_days' => [
                // Days between the request being raised and the leave starting.
                // Negative when somebody books leave that has already begun,
                // which is the case a rule is most often written to catch.
                'label' => 'Days of notice',
                'type'  => 'number',
            ],
            'department_id' => ['label' => 'Department', 'type' => 'lookup', 'lookup' => 'departments'],
        ],
    ];

    /**
     * Operators, and which field types may use them.
     *
     * `is` is deliberately loose (`==`) rather than strict: a value out of a
     * JSON column is a string, a leave type id out of the database is an int,
     * and a rule that silently never matches because of that is worse than one
     * that compares "3" to 3 and gets the answer anybody would expect.
     */
    public const OPERATORS = [
        'is'       => ['label' => 'is',          'types' => ['choice', 'lookup', 'number']],
        'is_not'   => ['label' => 'is not',      'types' => ['choice', 'lookup']],
        'at_least' => ['label' => 'is at least', 'types' => ['number']],
        'at_most'  => ['label' => 'is at most',  'types' => ['number']],
    ];

    /**
     * What a rule may do.
     *
     * Notify and record, nothing else — see the migration for why a rule may
     * not write to attendance or leave.
     */
    public const ACTION_NOTIFY_ROLE     = 'notify_role';
    public const ACTION_NOTIFY_MANAGER  = 'notify_manager';
    public const ACTION_NOTIFY_EMPLOYEE = 'notify_employee';
    public const ACTION_LOG             = 'log';

    public const ACTIONS = [
        self::ACTION_NOTIFY_ROLE     => 'Notify a role',
        self::ACTION_NOTIFY_MANAGER  => "Notify the employee's manager",
        self::ACTION_NOTIFY_EMPLOYEE => 'Notify the employee',
        self::ACTION_LOG             => 'Record it on the activity trail',
    ];

    /** Roles `notify_role` may address. Employee is absent on purpose — that is its own action. */
    public const NOTIFIABLE_ROLES = ['admin' => 'Administrators', 'hr' => 'HR', 'manager' => 'Managers'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The engine's own query: what should run, for this company, on this trigger. */
    public function scopeFiring(Builder $query, int $companyId, string $trigger): Builder
    {
        return $query->where('company_id', $companyId)
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('id');
    }

    /** The fields this rule's trigger allows. Empty for a trigger no longer in the code. */
    public function fields(): array
    {
        return self::FIELDS[$this->trigger] ?? [];
    }

    /**
     * A one-line reading of the rule's conditions, for the list.
     *
     * Built from the vocabulary rather than stored, so it cannot go stale
     * against a rule that was edited.
     *
     * `$lookups` carries the labels a row id stands for, keyed by the lookup
     * name in `FIELDS` — `['departments' => [3 => 'Ops'], ...]`. It is optional
     * and defaults to showing the id, because the engine and the notification
     * both call this on a path that has no business running three extra
     * queries; the screen that *can* afford them passes them in. A missing id
     * — a department deleted since the rule was written — shows as the id,
     * which is ugly and true, rather than blank.
     */
    public function summary(array $lookups = []): string
    {
        $fields = $this->fields();

        $parts = collect($this->conditions ?? [])
            ->map(function (array $condition) use ($fields, $lookups) {
                $field = $fields[$condition['field'] ?? ''] ?? null;
                $operator = self::OPERATORS[$condition['operator'] ?? ''] ?? null;

                if (! $field || ! $operator) {
                    // A condition the code no longer understands. Named rather
                    // than hidden: the rule will not fire and somebody has to
                    // be told which line is the reason.
                    return 'an unknown condition (' . ($condition['field'] ?? '?') . ')';
                }

                $value = $condition['value'] ?? '';

                $value = match ($field['type']) {
                    'choice' => $field['options'][$value] ?? $value,
                    'lookup' => $lookups[$field['lookup']][$value] ?? $value,
                    default  => $value,
                };

                return strtolower($field['label']) . ' ' . $operator['label'] . ' ' . $value;
            })
            ->all();

        return $parts ? implode(' and ', $parts) : 'every time';
    }

    /**
     * What the rule does, in the same shape as `summary()`.
     *
     * `notify_role` carries which role, because "notify a role" on a list of
     * six rules tells nobody anything. An action this version no longer has is
     * named rather than dropped, for the reason the conditions are: the rule
     * will behave differently from how it reads, and the list is where that
     * has to show.
     */
    public function actionSummary(): string
    {
        $parts = collect($this->actions ?? [])
            ->map(function (array $action) {
                $type = $action['type'] ?? '';

                if ($type === self::ACTION_NOTIFY_ROLE) {
                    $role = $action['role'] ?? null;

                    return 'notify ' . (self::NOTIFIABLE_ROLES[$role] ?? 'nobody — no role was chosen');
                }

                return isset(self::ACTIONS[$type])
                    ? lcfirst(self::ACTIONS[$type])
                    : 'an unknown action (' . ($type ?: '?') . ')';
            })
            ->all();

        return $parts ? ucfirst(implode(', and ', $parts)) : 'Nothing';
    }
}
