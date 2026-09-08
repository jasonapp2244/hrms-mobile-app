<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Applying a decision to a regularisation request (A4.13).
 *
 * The only place a request is allowed to affect attendance, and it does so
 * exclusively through the paths HR already uses by hand: AttendanceLog::void()
 * and AttendanceService::recordManual(). Nothing here writes to attendance_logs
 * directly, so an approved request cannot produce a punch with a thinner audit
 * trail than a manually keyed one.
 */
class RegularisationService
{
    public function __construct(
        protected AttendanceService $attendance,
    ) {}

    /**
     * Raise a request.
     *
     * Lives here rather than in a controller because there are two front doors
     * — the portal form and the app — and three of the rules below are the only
     * thing standing between a stale tab and two corrections for one problem.
     * A rule enforced in a form is a rule the other caller would not have.
     *
     * Failures are ValidationException, keyed on the field the person can
     * actually change, so the web redirect and the API's `validation_failed`
     * both say the same thing in the same place.
     *
     * @param  array{attendance_log_id?: int|null, type: string, requested_at: string, reason: string}  $data
     *
     * @throws ValidationException
     */
    public function submit(Employee $employee, array $data): AttendanceRegularisation
    {
        $timezone = $employee->company?->tz() ?? config('app.timezone');
        $at       = Carbon::parse($data['requested_at'], $timezone);

        if ($at->isFuture()) {
            throw ValidationException::withMessages([
                'requested_at' => 'You cannot ask for a correction to a time that has not happened yet.',
            ]);
        }

        // A challenged punch must be one of this employee's own. Checked rather
        // than trusted: the id arrives from a form field anyone can retype, and
        // from a JSON body anyone can post.
        $challenged = null;

        if (! empty($data['attendance_log_id'])) {
            $challenged = AttendanceLog::where('employee_id', $employee->id)
                ->find($data['attendance_log_id']);

            if (! $challenged) {
                throw ValidationException::withMessages([
                    'attendance_log_id' => 'That punch is not on your record.',
                ]);
            }
        }

        // One open request per punch, or per date-and-type where there is no
        // punch. Without this a double submit produces two approvals and two
        // corrections for the same problem.
        $duplicate = AttendanceRegularisation::where('employee_id', $employee->id)
            ->pending()
            ->when(
                $challenged,
                fn ($q) => $q->where('attendance_log_id', $challenged->id),
                fn ($q) => $q->whereNull('attendance_log_id')
                    ->whereDate('work_date', $at->toDateString())
                    ->where('type', $data['type']),
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'reason' => 'You already have a request waiting on this. Wait for it to be decided first.',
            ]);
        }

        return AttendanceRegularisation::create([
            'company_id'        => $employee->company_id,
            'employee_id'       => $employee->id,
            'attendance_log_id' => $challenged?->id,
            'office_id'         => $challenged?->office_id ?? $employee->office_id,
            'work_date'         => $at->toDateString(),
            'type'              => $data['type'],
            'requested_at'      => $at,
            'reason'            => $data['reason'],
        ]);
    }

    /**
     * Withdraw a request the employee no longer wants decided.
     *
     * Only while it is still pending: a decided request has already moved
     * attendance, and "cancelling" it afterwards would leave the correction in
     * place with nothing on record explaining it.
     *
     * @throws ValidationException
     */
    public function cancel(AttendanceRegularisation $request): AttendanceRegularisation
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'That request has already been decided.',
            ]);
        }

        $request->update(['status' => 'cancelled']);

        return $request->refresh();
    }

    /**
     * Approve a request: strike out the challenged punch if there is one, then
     * record the corrected reading.
     *
     * Wrapped in a transaction because the two halves are one correction. A void
     * that lands without its replacement leaves the employee worse off than
     * before they asked — the failure mode most likely to go unnoticed, since
     * the request would still read "approved".
     */
    public function approve(AttendanceRegularisation $request, User $actor, ?string $note = null): AttendanceRegularisation
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $actor, $note) {
            $employee = $request->employee;

            if (! $employee) {
                throw new RuntimeException('This request has no employee attached and cannot be applied.');
            }

            // Fall back to the employee's own office: the request only carries
            // one when the employee was somewhere other than usual.
            $office = $request->office
                ?? $employee->office
                ?? Office::where('company_id', $employee->company_id)->first();

            if (! $office) {
                throw new RuntimeException('No office could be resolved for this correction.');
            }

            $challenged = $request->attendanceLog;

            if ($challenged && ! $challenged->isVoided()) {
                $challenged->void(
                    $actor,
                    sprintf('Regularisation #%d approved — %s', $request->id, $request->reason),
                );
            }

            $log = $this->attendance->recordManual(
                $employee,
                $office,
                $request->type,
                $request->requested_at,
                sprintf('Regularisation #%d — %s', $request->id, $request->reason),
            );

            $request->forceFill([
                'status'             => 'approved',
                'decided_by_user_id' => $actor->id,
                'decided_by_label'   => $actor->name,
                'decided_at'         => now(),
                'decision_note'      => $note,
                'created_log_id'     => $log->id,
            ])->save();

            return $request->refresh();
        });
    }

    /** Reject a request. Attendance is left exactly as it was. */
    public function reject(AttendanceRegularisation $request, User $actor, ?string $note = null): AttendanceRegularisation
    {
        $this->assertPending($request);

        $request->forceFill([
            'status'             => 'rejected',
            'decided_by_user_id' => $actor->id,
            'decided_by_label'   => $actor->name,
            'decided_at'         => now(),
            'decision_note'      => $note,
        ])->save();

        return $request->refresh();
    }

    /**
     * A decision is made once.
     *
     * Approving an already-approved request would void a second punch and write
     * a second correction, quietly doubling the day — the kind of thing a double
     * submit or a stale tab produces without anyone meaning to.
     */
    protected function assertPending(AttendanceRegularisation $request): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException(
                'This request has already been ' . $request->status . ' and cannot be decided again.',
            );
        }
    }
}
