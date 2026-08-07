<?php

namespace App\Services;

use App\Models\AttendanceRegularisation;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
