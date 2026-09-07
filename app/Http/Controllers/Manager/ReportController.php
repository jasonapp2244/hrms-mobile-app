<?php

namespace App\Http\Controllers\Manager;

use App\Services\ManagerScope;
use App\Services\ReportService;
use Illuminate\Http\Request;

/**
 * Team-scoped reporting.
 *
 * The same ReportService the HR screens use, handed the manager's team as an
 * explicit list of employee ids. Not a second reporting engine: the uniform
 * `title / subtitle / tiles / headings / rows` shape is what makes one Blade
 * partial render every report in the product, and a manager's Late Arrivals
 * ought to be the same report HR reads, over fewer people.
 *
 * Three reports rather than the HR set of nine, because the other six answer
 * company questions — outliers across departments, payroll hours, the leave
 * register — that a team lead has no remit over.
 *
 * No PDF or Excel. Exporting is `export-reports`, which the manager role does
 * not hold; the browser's own print is wired up instead, which covers the
 * "print my team's late list" case without widening the role. Adding the
 * permission would be safe today — every admin export route sits behind
 * `role:admin|hr`, which a manager can never pass — but it would be a
 * permission granted for a reason the route table does not record, and the next
 * person to add an `export-reports` route outside that group would hand
 * managers something nobody decided to give them.
 */
class ReportController extends Controller
{
    /**
     * What a manager may run, and which service method answers it.
     *
     * Declared as data so the screen, the navigation and the validation cannot
     * drift apart — an unknown type 404s here rather than reaching the service
     * with a method name off the query string.
     */
    public const REPORTS = [
        'late' => [
            'label' => 'Late Arrivals',
            'blurb' => 'Who on your team has been clocking in after their shift starts.',
        ],
        'overtime' => [
            'label' => 'Overtime',
            'blurb' => 'Hours worked beyond the rostered shift, per person.',
        ],
        'weekly' => [
            'label' => 'Weekly Rollup',
            'blurb' => 'Attendance, lateness and absence for your team, a row per week.',
        ],
    ];

    public function __construct(
        ManagerScope $scope,
        protected ReportService $reports,
    ) {
        parent::__construct($scope);
    }

    public function show(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, self::REPORTS), 404);

        $manager = $this->manager();
        $timezone = $this->timezone($manager);

        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to'   => 'nullable|date_format:Y-m-d',
        ]);

        // validate() returns no key at all for an absent nullable field, so the
        // fallback has to survive the key being missing rather than null.
        $from = ($data['from'] ?? null) ?: now($timezone)->startOfMonth()->toDateString();
        $to   = ($data['to'] ?? null) ?: now($timezone)->toDateString();

        // A backwards range returns nothing and reads like a broken report;
        // swapping it is what the person meant.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $teamIds = $this->scope->teamIds($manager);

        // The scope travels as an explicit id list. An empty team yields an
        // empty report rather than the company's, because the service treats []
        // as nobody and only null as everybody.
        $report = $this->reports->{$type}($manager->company_id, $from, $to, null, $teamIds);

        $report['from']    = $from;
        $report['to']      = $to;
        $report['type']    = $type;
        $report['manager'] = $manager;
        $report['reports'] = self::REPORTS;
        $report['team_size'] = count($teamIds);

        // Says whose report this is, on the screen and on the printout. Without
        // it a printed page is indistinguishable from the company-wide version.
        $report['subtitle'] = $report['subtitle'] . ' — your team only';

        return view('manager.reports.show', $report);
    }
}
