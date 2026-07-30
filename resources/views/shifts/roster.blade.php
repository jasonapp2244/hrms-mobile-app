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
  <div class="d-flex align-items-center flex-wrap">
    <a href="{{ route('shifts.index') }}" class="btn btn-outline-primary"><i class="ti ti-clock-hour-4 me-1"></i>Manage Shifts</a>
  </div>
</div>

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
                $shift   = $emp->shift;                 // own shift, else the department's
                $status  = $attendance[$emp->id][$dateStr] ?? null;
                $isPast  = $day->lt($today);
                $isToday = $day->isSameDay($today);
                $isWeekend = in_array($day->dayOfWeek, $weekend, true);
                $isHoliday = $holidays->has($dateStr);
                $isOnLeave = ($onLeave[$emp->id] ?? collect())->has($dateStr);
              @endphp
              <td class="{{ $isToday ? 'table-active' : '' }}" style="min-width:100px">
                @if($isWeekend)
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
</div>
@endsection
