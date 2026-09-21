<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\PolicyRule;
use App\Models\User;
use App\Notifications\PolicyRuleFired;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Evaluates the company's conditional rules (A2.9, A6.6).
 *
 * The one thing to hold on to when changing anything here: **this runs inside
 * the path that records a punch.** An employee standing at a kiosk with a
 * finger on the button is the caller. So every layer below fails soft — a bad
 * rule row, a field the code no longer has, an action naming nobody, a mail
 * server that is down — and the punch is written regardless. A rules feature
 * that can refuse attendance is worse than no rules feature.
 *
 * That is also why nothing here throws upward, and why each rule is run in its
 * own try: one malformed rule must not silence the three good ones after it.
 *
 * Read `PolicyRule` first. The vocabulary of fields, operators and actions
 * lives there, so the form, the validator and this class cannot disagree.
 */
class RuleEngine
{
    /**
     * Run every active rule for this trigger.
     *
     * `$context` is the flat set of values a condition may ask about, already
     * resolved by the caller — the engine does not query for them, because the
     * caller has just written the row they come from and a second read would be
     * both wasteful and capable of disagreeing with it.
     *
     * @param  array<string, mixed>  $context
     * @return Collection<int, PolicyRule>  the rules that matched and ran
     */
    public function fire(
        string $trigger,
        ?int $companyId,
        Employee $employee,
        array $context,
        ?Model $subject = null,
    ): Collection {
        $fired = collect();

        if (! $companyId) {
            return $fired;
        }

        try {
            $rules = PolicyRule::query()->firing($companyId, $trigger)->get();
        } catch (Throwable $e) {
            // The table is missing, or the database is unreachable. Neither is
            // a reason to refuse the punch that got us here.
            report($e);

            return $fired;
        }

        foreach ($rules as $rule) {
            try {
                if (! $this->matches($rule, $context)) {
                    continue;
                }

                $this->run($rule, $employee, $context, $subject);
                $fired->push($rule);
            } catch (Throwable $e) {
                // This rule is broken. The next one might not be.
                report($e);
            }
        }

        return $fired;
    }

    /**
     * Whether every condition on a rule holds.
     *
     * An empty condition list is true — "every time" is a legible thing to
     * want, and an empty AND is true in every language that has one.
     *
     * A condition naming a field or operator this version does not have is
     * **false, not skipped**: it cannot be shown to hold, and a rule that
     * quietly drops half its conditions would fire more widely than the person
     * who wrote it asked for. `PolicyRule::summary()` names the broken line on
     * the screen so somebody can fix it.
     *
     * @param  array<string, mixed>  $context
     */
    public function matches(PolicyRule $rule, array $context): bool
    {
        $fields = $rule->fields();

        foreach ($rule->conditions ?? [] as $condition) {
            $name = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;

            $field = $fields[$name] ?? null;

            if (! $field || ! isset(PolicyRule::OPERATORS[$operator])) {
                return false;
            }

            // An operator that does not belong to this field's type — `is_not`
            // on a number, say. Refused here as well as on the form, because a
            // row can outlive the form that wrote it.
            if (! in_array($field['type'], PolicyRule::OPERATORS[$operator]['types'], true)) {
                return false;
            }

            // A context the caller did not supply this key for. Not an error —
            // a punch has no leave type — but nothing to compare either.
            if (! array_key_exists($name, $context)) {
                return false;
            }

            if (! $this->compare($field['type'], $operator, $context[$name], $condition['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One comparison.
     *
     * Numbers are compared as numbers, which is the whole reason this is not
     * three inline `match` arms: the rule's value comes out of a JSON column as
     * a string, and `"9" >= "20"` is true as strings and false as the
     * arithmetic anybody writing that rule meant.
     *
     * `is` on a choice or a lookup is deliberately loose (`==`), because the
     * stored side is a string and the context side is often an int id.
     */
    protected function compare(string $type, string $operator, mixed $actual, mixed $expected): bool
    {
        if ($type === 'number' || $operator === 'at_least' || $operator === 'at_most') {
            if (! is_numeric($actual) || ! is_numeric($expected)) {
                return false;
            }

            $actual = (float) $actual;
            $expected = (float) $expected;

            return match ($operator) {
                'at_least' => $actual >= $expected,
                'at_most'  => $actual <= $expected,
                'is'       => $actual == $expected,
                default    => false,
            };
        }

        return match ($operator) {
            'is'     => $actual == $expected,
            'is_not' => $actual != $expected,
            default  => false,
        };
    }

    /**
     * Do what the rule says, then record that it did.
     *
     * Every action runs, in the order they were written, and one that fails
     * does not stop the next: a rule that both notifies and logs should still
     * log when there is nobody to notify.
     *
     * `last_fired_at` is written with `saveQuietly` and no touch of
     * `updated_at`, because the list shows both and they answer different
     * questions — when somebody last *edited* this rule, and when it last
     * *matched*. Firing is not an edit.
     *
     * @param  array<string, mixed>  $context
     */
    protected function run(
        PolicyRule $rule,
        Employee $employee,
        array $context,
        ?Model $subject,
    ): void {
        $sentence = $context['summary'] ?? ($employee->full_name . ' matched this rule.');

        foreach ($rule->actions ?? [] as $action) {
            try {
                $this->act($rule, $action, $employee, $sentence, $subject);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $rule->forceFill(['last_fired_at' => now()])->saveQuietly();
    }

    /** @param array<string, mixed> $action */
    protected function act(
        PolicyRule $rule,
        array $action,
        Employee $employee,
        string $sentence,
        ?Model $subject,
    ): void {
        $type = $action['type'] ?? null;

        match ($type) {
            PolicyRule::ACTION_LOG => $this->log($rule, $employee, $sentence, $subject),

            PolicyRule::ACTION_NOTIFY_EMPLOYEE => $this->notify(
                collect([$employee->user])->filter(),
                $rule,
                $sentence,
            ),

            PolicyRule::ACTION_NOTIFY_MANAGER => $this->notify(
                collect([$employee->manager?->user])->filter(),
                $rule,
                $sentence,
            ),

            PolicyRule::ACTION_NOTIFY_ROLE => $this->notify(
                $this->holders($rule->company_id, $action['role'] ?? null),
                $rule,
                $sentence,
            ),

            // An action written by a later version, or a typo that got past a
            // form that no longer exists. Ignored rather than fatal, so the
            // other actions on the rule still run.
            default => null,
        };
    }

    /**
     * Everybody in this company holding a role.
     *
     * Scoped to the company for the same reason every other listing is: a rule
     * is one client's, and "tell HR" means this client's HR.
     *
     * @return Collection<int, User>
     */
    protected function holders(?int $companyId, ?string $role): Collection
    {
        if (! $companyId || ! $role || ! isset(PolicyRule::NOTIFIABLE_ROLES[$role])) {
            return collect();
        }

        return User::where('company_id', $companyId)
            ->where('is_active', '!=', false)
            ->role($role)
            ->get();
    }

    /**
     * Deliver, swallowing anything that goes wrong.
     *
     * Same rule as `NotificationService`: losing the message is bad, losing the
     * punch that caused it would be worse.
     *
     * **No link goes with it**, which is why nothing here carries a url. One
     * rule can address HR, an administrator, the line manager and the employee
     * at once, and a notification carries a single destination for all of them;
     * the screens worth linking to are permission-gated, so most of that
     * audience would tap through to a refusal. The sentence is the whole
     * message, and an unlinked notification opens the notification list rather
     * than failing (see `NotificationController::open`).
     *
     * @param  Collection<int, User>  $recipients
     */
    protected function notify(Collection $recipients, PolicyRule $rule, string $sentence): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new PolicyRuleFired(
                ruleId: $rule->id,
                ruleName: $rule->name,
                subject: $sentence,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Write it to the security trail.
     *
     * The actor is the employee whose punch or request matched, not the
     * administrator who wrote the rule — the trail answers "what happened to
     * whom", and the rule is the subject rather than the author. `companyId` is
     * passed explicitly because the punch may have arrived over the API with no
     * web session behind it, and `record()` would otherwise have nothing to
     * take it from.
     */
    protected function log(PolicyRule $rule, Employee $employee, string $sentence, ?Model $subject): void
    {
        ActivityLog::record(
            event: ActivityLog::RULE_FIRED,
            description: $rule->name . ' — ' . $sentence,
            actor: $employee->user,
            subject: $subject ?? $rule,
            actorLabel: $employee->full_name,
            companyId: $rule->company_id,
        );
    }
}
