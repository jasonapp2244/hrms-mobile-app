<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\AttendanceRegularisation;
use App\Services\AttendanceService;
use App\Services\RegularisationService;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee asking for their attendance to be corrected, from the phone
 * (A4.13 on the API side).
 *
 * **Raising only.** Deciding one is HR's, on the web, and stays there: approval
 * voids a punch and writes a replacement through `RegularisationService`, and
 * `manage-attendance` is the key to that. A manager holds neither the key nor a
 * place in this chain — leave approval is manager-then-HR, but a correction is
 * HR's alone, so putting an approve button in the app's manager tab would
 * advertise a step that does not exist.
 *
 * Every rule beyond the shape of the request — no future times, only your own
 * punches, one open request per problem — comes from the same service the
 * portal form posts to.
 */
class RegularisationController extends ApiController
{
    /** How far back the challengeable punch list reaches. Matches the portal. */
    public const RECENT_PUNCHES = 30;

    public function __construct(
        protected RegularisationService $regularisation,
        protected AttendanceService $attendance,
    ) {}

    /**
     * The employee's own requests, newest first, and the punches they could
     * challenge.
     *
     * The punch list ships with the list rather than as an endpoint of its own
     * because it is not useful apart from it: `/attendance/history` answers in
     * day-shaped rows and carries no punch ids, so without this the app has no
     * way to name the reading it is disputing.
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee();
        $timezone = $this->timezone($employee);

        $data = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,cancelled',
            'page'   => 'nullable|integer|min:1',
        ]);

        $query = AttendanceRegularisation::with(['attendanceLog', 'createdLog'])
            ->where('employee_id', $employee->id)
            ->latest();

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        $page = $query->paginate(15);

        // Voided punches are already excluded by the model's global scope —
        // there is nothing to dispute about a reading that has been struck out.
        $punches = AttendanceLog::with('office')
            ->where('employee_id', $employee->id)
            ->latest('scanned_at')
            ->limit(self::RECENT_PUNCHES)
            ->get();

        return $this->ok([
            'requests' => collect($page->items())
                ->map(fn (AttendanceRegularisation $r) => $this->payload($r, $timezone))
                ->values(),
            'meta' => $this->pageMeta($page),
            'recent_punches' => $punches->map(fn (AttendanceLog $log) => [
                'id'         => $log->id,
                'type'       => $log->type,
                'status'     => $log->status,
                'work_date'  => $log->work_date?->toDateString(),
                'scanned_at' => $this->attendance->wallClock($log->scanned_at, $timezone)->toIso8601String(),
                'time'       => Clock::time($this->attendance->wallClock($log->scanned_at, $timezone)),
                'office'     => $log->office?->name,
            ])->values(),
        ]);
    }

    /**
     * Raise one.
     *
     * `attendance_log_id` present means "this reading is wrong"; absent means
     * "there should be a punch here and there isn't". The service tells the two
     * apart and refuses a second open request for either.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->employee()->load('company');

        $data = $request->validate([
            'attendance_log_id' => 'nullable|integer',
            'type'              => 'required|in:in,out',
            'requested_at'      => 'required|date',
            'reason'            => 'required|string|min:5|max:500',
        ]);

        // Business-rule failures arrive as ValidationException, so they reach
        // the app in the same per-field shape a malformed date would.
        $created = $this->regularisation->submit($employee, $data);

        return $this->ok([
            'request' => $this->payload($created, $this->timezone($employee)),
            'message' => __('api.correction_submitted'),
        ], 201);
    }

    /** Withdraw one that has not been decided yet. */
    public function cancel(AttendanceRegularisation $regularisation): JsonResponse
    {
        $employee = $this->employee();

        abort_unless(
            $regularisation->employee_id === $employee->id,
            403,
            __('api.correction_not_yours'),
        );

        $cancelled = $this->regularisation->cancel($regularisation);

        return $this->ok([
            'request' => $this->payload($cancelled, $this->timezone($employee)),
            'message' => __('api.correction_withdrawn'),
        ]);
    }

    protected function payload(AttendanceRegularisation $r, string $timezone): array
    {
        return [
            'id'           => $r->id,
            'type'         => $r->type,
            'work_date'    => $r->work_date?->toDateString(),
            'requested_at' => $this->attendance->wallClock($r->requested_at, $timezone)->toIso8601String(),
            'reason'       => $r->reason,
            'status'       => $r->status,
            // Whether this disputes a reading or reports a missing one. The app
            // words the row differently for each, and deriving it from a null
            // id in two places is how the two wordings drift apart.
            'challenges_a_punch' => $r->challengesAPunch(),
            'attendance_log_id'  => $r->attendance_log_id,
            'can_cancel'         => $r->isPending(),
            'submitted_at'       => $r->created_at?->toIso8601String(),
            // Who decided, and what they said. `decided_by_label` is kept on the
            // row rather than read through the relation so a decision still
            // names its author after that account is gone.
            'decision_note' => $r->decision_note,
            'decided_by'    => $r->decided_by_label,
            'decided_at'    => $r->decided_at?->toIso8601String(),
        ];
    }
}
