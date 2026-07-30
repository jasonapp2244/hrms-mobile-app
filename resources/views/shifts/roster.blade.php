@extends('layouts.app')
@section('title','Weekly Roster')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Weekly Roster</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('shifts.index') }}">Shifts &amp; Schedule</a></li>
      <li class="breadcrumb-item active">Weekly Roster</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap gap-2">
    @if($planning)
      <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#rotationModal">
        <i class="ti ti-repeat me-1"></i>Generate Rotation
      </button>
      @php
        // With nothing planned there is nothing to withdraw either, so the
        // button stays on "Publish" and is simply disabled.
        $canPublish = $unpublishedCount > 0 || $plannedCount === 0;
      @endphp
      <form action="{{ route('shifts.roster.publish') }}" method="POST" class="d-inline">
        @csrf
        <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
        <input type="hidden" name="action" value="{{ $canPublish ? 'publish' : 'unpublish' }}">
        <button type="submit" class="btn btn-{{ $canPublish ? 'success' : 'outline-warning' }}"
                @disabled($plannedCount === 0)>
          <i class="ti ti-{{ $canPublish ? 'send' : 'eye-off' }} me-1"></i>
          {{ $canPublish ? 'Publish Week' : 'Withdraw Week' }}
        </button>
      </form>
      <a href="{{ route('shifts.roster', ['week' => $weekStart->toDateString()]) }}" class="btn btn-light">Done</a>
    @else
      <a href="{{ route('shifts.roster', ['week' => $weekStart->toDateString(), 'plan' => 1]) }}" class="btn btn-primary">
        <i class="ti ti-edit me-1"></i>Plan Week
      </a>
      <a href="{{ route('shifts.index') }}" class="btn btn-outline-primary"><i class="ti ti-clock-hour-4 me-1"></i>Manage Shifts</a>
    @endif
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

@if($planning)
  <div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    Set anyone's shift for a specific day. <strong>Follow standing shift</strong> leaves the day to
    their own shift or their department's, which is what applies where nothing is planned.
    @if($plannedCount > 0)
      <div class="mt-1">
        {{ $plannedCount }} day(s) planned this week
        @if($unpublishedCount > 0)
          — <strong>{{ $unpublishedCount }} not yet visible to staff.</strong>
        @else
          — all published.
        @endif
      </div>
    @endif
  </div>
@endif

{{-- Week navigation --}}
<div class="card mb-3">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
    <a href="{{ route('shifts.roster', ['week' => $weekStart->copy()->subWeek()->toDateString()]) }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-chevron-left"></i> Prev</a>
    <div class="text-center">
      <h5 class="mb-0">{{ $weekStart->format('M j') }} – {{ $weekEnd->format('M j, Y') }}</h5>
      <a href="{{ route('shifts.roster') }}" class="small text-decoration-none">This week</a>
    </div>
    <a href="{{ route('shifts.roster', ['week' => $weekStart->copy()->addWeek()->toDateString()]) }}" class="btn btn-sm btn-outline-secondary">Next <i class="ti ti-chevron-right"></i></a>
  </div>
</div>

{{-- Legend --}}
<div class="d-flex flex-wrap gap-3 mb-3 small text-muted">
  <span><span class="badge bg-success">&nbsp;</span> Present</span>
  <span><span class="badge bg-warning">&nbsp;</span> Late</span>
  <span><span class="badge bg-danger">&nbsp;</span> Absent</span>
  <span><span class="badge bg-secondary-transparent text-secondary">&nbsp;</span> Scheduled</span>
  <span><span class="badge bg-info">&nbsp;</span> On Leave</span>
  <span><span class="badge bg-primary-transparent text-primary">&nbsp;</span> Holiday</span>
  <span><span class="badge bg-light text-dark">Off</span> Non-working day</span>
</div>

<form action="{{ route('shifts.roster.save') }}" method="POST">
@csrf
<input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered mb-0 align-middle text-center" style="min-width:900px">
        <thead>
          <tr>
            <th class="text-start" style="min-width:190px">Employee</th>
            @foreach($days as $day)
              <th class="{{ $day->isSameDay($today) ? 'table-active' : '' }}">
                <div class="fw-semibold">{{ $day->format('D') }}</div>
                <small class="text-muted">{{ $day->format('m/d') }}</small>
              </th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @forelse($employees as $emp)
          <tr>
            <td class="text-start">
              <div class="fw-semibold">{{ $emp->full_name }}</div>
              <small class="text-muted">
                {{ $emp->department->name ?? '—' }}
                @if($emp->hasShiftOverride())
                  <span class="badge bg-info-transparent text-info ms-1" style="font-size:9px"
                        title="On their own shift, not the department's">own shift</span>
                @endif
              </small>
            </td>
            @foreach($days as $day)
              @php
                $dateStr = $day->toDateString();
                $assignment = $plan[$emp->id][$dateStr] ?? null;
                // A planned day wins; otherwise the standing shift applies.
                // A rostered day off resolves to no shift at all.
                $shift   = $assignment
                             ? ($assignment->is_day_off ? null : $assignment->shift)
                             : $emp->shift;
                $status  = $attendance[$emp->id][$dateStr] ?? null;
                $isPast  = $day->lt($today);
                $isToday = $day->isSameDay($today);
                $isWeekend = in_array($day->dayOfWeek, $weekend, true);
                $isHoliday = $holidays->has($dateStr);
                $isOnLeave = ($onLeave[$emp->id] ?? collect())->has($dateStr);
                $rosteredOff = $assignment?->is_day_off;
              @endphp
              <td class="{{ $isToday ? 'table-active' : '' }}" style="min-width:100px">
                @if($planning)
                  <select name="roster[{{ $emp->id }}][{{ $dateStr }}]" class="form-select form-select-sm mb-1" style="font-size:11px">
                    <option value="">Follow standing shift</option>
                    <option value="off" @selected($rosteredOff)>Day off</option>
                    @foreach($shifts as $s)
                      <option value="{{ $s->id }}" @selected($assignment && ! $assignment->is_day_off && $assignment->shift_id === $s->id)>
                        {{ $s->code ?? $s->name }} {{ $s->timing }}
                      </option>
                    @endforeach
                  </select>
                  @if($assignment && ! $assignment->isPublished())
                    <span class="badge bg-warning-transparent text-warning" style="font-size:9px">Draft</span>
                  @endif
                @elseif($rosteredOff)
                  <span class="badge bg-light text-dark">Rostered off</span>
                @elseif($isWeekend)
                  <span class="badge bg-light text-dark">Off</span>
                @elseif($isHoliday)
                  <span class="badge bg-primary-transparent text-primary">Holiday</span>
                @elseif($isOnLeave)
                  {{-- Booked and approved: not absent, whatever the punches say. --}}
                  <span class="badge bg-info">On Leave</span>
                @elseif(! $shift)
                  <span class="text-muted">—</span>
                @else
                  {{-- Scheduled shift chip --}}
                  <div class="d-inline-flex align-items-center px-2 py-1 rounded mb-1"
                       style="background:{{ $shift->color }}1a;border:1px solid {{ $shift->color }}55;font-size:11px;line-height:1.2">
                    <span class="d-inline-block me-1" style="width:8px;height:8px;border-radius:50%;background:{{ $shift->color }}"></span>
                    <span class="fw-semibold">{{ $shift->code ?? $shift->name }}</span>
                  </div>
                  <div class="text-muted" style="font-size:10px">
                    {{ $shift->timing }}@if($shift->crossesMidnight())<span title="Ends the next morning">+1</span>@endif
                  </div>
                  {{-- Attendance status --}}
                  <div class="mt-1">
                    @if($status === 'ontime')
                      <span class="badge bg-success" style="font-size:10px">Present</span>
                    @elseif($status === 'late')
                      <span class="badge bg-warning" style="font-size:10px">Late</span>
                    @elseif($isPast)
                      <span class="badge bg-danger" style="font-size:10px">Absent</span>
                    @else
                      <span class="badge bg-secondary-transparent text-secondary" style="font-size:10px">Scheduled</span>
                    @endif
                  </div>
                @endif
              </td>
            @endforeach
          </tr>
          @empty
          <tr><td colspan="8" class="text-muted py-4">No active employees to schedule.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  @if($planning)
    <div class="card-footer d-flex justify-content-end gap-2">
      <a href="{{ route('shifts.roster', ['week' => $weekStart->toDateString()]) }}" class="btn btn-light">Cancel</a>
      <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Roster</button>
    </div>
  @endif
</div>
</form>

@if($planning)
{{-- Rotation generator --}}
<div class="modal fade" id="rotationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form action="{{ route('shifts.roster.rotation') }}" method="POST">
        @csrf
        <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
        <div class="modal-header">
          <h5 class="modal-title">Generate Rotation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted">
            The pattern is applied to consecutive days from the start date and then repeats.
            Its <strong>length</strong> is what makes it rotate: seven entries repeat on the same
            weekdays forever, while four entries walk around the week — which is what
            "four on, four off" actually means.
          </p>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Start date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" class="form-control" value="{{ $weekStart->toDateString() }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Repeat for <span class="text-danger">*</span></label>
              <select name="weeks" class="form-select">
                @foreach(range(1, 12) as $w)
                  <option value="{{ $w }}" @selected($w === 4)>{{ $w }} week{{ $w > 1 ? 's' : '' }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <label class="form-label">Pattern <span class="text-danger">*</span></label>
          <div class="row g-2 mb-1">
            @foreach(range(1, 7) as $slot)
              <div class="col">
                <div class="text-muted text-center" style="font-size:11px">Day {{ $slot }}</div>
                <select name="cycle[]" class="form-select form-select-sm" style="font-size:11px">
                  <option value="">—</option>
                  <option value="off">Off</option>
                  @foreach($shifts as $s)
                    <option value="{{ $s->id }}">{{ $s->code ?? $s->name }}</option>
                  @endforeach
                </select>
              </div>
            @endforeach
          </div>
          <div class="form-text mb-3">Leave the trailing days as “—” to shorten the cycle.</div>

          <label class="form-label">Employees <span class="text-danger">*</span></label>
          <div class="border rounded p-2" style="max-height:220px;overflow-y:auto">
            @foreach($employees as $emp)
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="employee_ids[]" value="{{ $emp->id }}" id="rot{{ $emp->id }}">
                <label class="form-check-label" for="rot{{ $emp->id }}">
                  {{ $emp->full_name }} <span class="text-muted small">{{ $emp->department->name ?? '—' }}</span>
                </label>
              </div>
            @endforeach
          </div>

          <div class="alert alert-warning mt-3 mb-0 py-2 small">
            <i class="ti ti-alert-triangle me-1"></i>
            Any existing plan for these people inside the generated period is replaced.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Generate</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endif
@endsection
