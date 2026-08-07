<?php

namespace App\Support;

use App\Models\User;

/**
 * The dashboard's panels: what exists, who may see each, and what each role
 * gets by default (A8.4, A8.5).
 *
 * Declared as data because three places have to agree and must not drift: the
 * dashboard that renders the panels, the settings screen that offers them as
 * checkboxes, and the controller that decides which queries are worth running.
 * That last one is the reason this is not just a Blade concern — a panel nobody
 * is showing should cost nothing, and the only way to know is to ask first.
 */
class DashboardWidgets
{
    /**
     * key => [label, blurb, permission, roles]
     *
     * `permission` is the gate: a widget whose permission the viewer lacks is
     * neither shown nor offered, whatever their saved preferences say. Null
     * means anybody who can reach the dashboard at all.
     *
     * `roles` is only the *default* — which roles get it switched on out of the
     * box. Anybody may turn on any widget they are permitted to see, because
     * "HR never wants the security panel" is a guess and this is their screen.
     */
    public const WIDGETS = [
        'tiles' => [
            'label'      => 'Headcount tiles',
            'blurb'      => 'Present, late, on leave, absent and total staff for today.',
            'permission' => null,
            'roles'      => ['admin', 'hr'],
        ],
        'week_comparison' => [
            'label'      => 'This week vs last week',
            'blurb'      => 'Attendance, lateness and absence, and which way each is moving.',
            'permission' => 'view-reports',
            'roles'      => ['admin', 'hr'],
        ],
        'attendance_trend' => [
            'label'      => 'Seven-day attendance chart',
            'blurb'      => 'How many people were in each day for the last week.',
            'permission' => null,
            'roles'      => ['admin', 'hr'],
        ],
        'who_is_in' => [
            'label'      => 'Who is in right now',
            'blurb'      => 'A live count, and who is unaccounted for.',
            'permission' => 'view-attendance',
            'roles'      => ['admin', 'hr'],
        ],
        'pending_approvals' => [
            'label'      => 'Waiting on you',
            'blurb'      => 'Leave requests, regularisations and shift swaps needing a decision.',
            'permission' => 'view-attendance',
            'roles'      => ['hr'],
        ],
        'document_expiries' => [
            'label'      => 'Documents expiring',
            'blurb'      => 'Contracts and permits lapsing in the next month, or already lapsed.',
            'permission' => 'manage-employees',
            'roles'      => ['hr'],
        ],
        'recent_activity' => [
            'label'      => 'Recent punches',
            'blurb'      => 'The last ten clock-ins and clock-outs.',
            'permission' => 'view-attendance',
            'roles'      => ['admin', 'hr'],
        ],
        'security' => [
            'label'      => 'Security',
            'blurb'      => 'Failed sign-ins, lockouts, and how many staff accounts carry a second factor.',
            'permission' => 'manage-settings',
            'roles'      => ['admin'],
        ],
    ];

    /** Every widget this user is allowed to see, in declaration order. */
    public static function availableTo(User $user): array
    {
        return array_filter(
            self::WIDGETS,
            fn (array $widget) => $widget['permission'] === null || $user->can($widget['permission']),
        );
    }

    /**
     * What this user's dashboard should show.
     *
     * Their saved choice, narrowed to what they are permitted — a widget stays
     * in the stored list when a permission is taken away, so that a permission
     * restored later brings the panel back rather than silently losing it.
     *
     * @return array<int, string>
     */
    public static function forUser(User $user): array
    {
        $allowed = array_keys(self::availableTo($user));
        $chosen = $user->dashboard_widgets;

        // Null is "never chosen", which is not the same as "chose nothing".
        if (! is_array($chosen)) {
            $chosen = self::defaultsFor($user);
        }

        return array_values(array_intersect($chosen, $allowed));
    }

    /**
     * The out-of-the-box set for whichever role the user holds (A8.4).
     *
     * An admin and an HR user get different dashboards because they open it
     * looking for different things: one for who is in and what needs approving,
     * the other for whether anything is wrong with the system.
     *
     * @return array<int, string>
     */
    public static function defaultsFor(User $user): array
    {
        $roles = $user->getRoleNames()->all();

        $keys = [];

        foreach (self::WIDGETS as $key => $widget) {
            if (array_intersect($roles, $widget['roles'])) {
                $keys[] = $key;
            }
        }

        // A role nobody anticipated still gets a usable screen rather than a
        // blank one.
        return $keys ?: ['tiles', 'attendance_trend'];
    }
}
