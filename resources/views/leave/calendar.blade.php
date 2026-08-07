@extends('layouts.app')
@section('title','Leave Calendar')

@php
	// The grid starts on the Sunday on or before the 1st and runs in whole weeks,
	// so every row has seven cells and the month sits where the eye expects it.
	$gridStart = $month->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::SUNDAY);
	$gridEnd   = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SATURDAY);
	$today     = now()->toDateString();
@endphp

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Leave Calendar</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('leave.index') }}">Leave</a></li>
      <li class="breadcrumb-item active">Calendar</li>
    </ol></nav></div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="{{ route('leave.calendar', array_merge(request()->only('department_id'), ['month' => $month->copy()->subMonth()->format('Y-m')])) }}"
       class="btn btn-outline-secondary"><i class="ti ti-chevron-left"></i></a>
    <span class="fw-semibold px-2">{{ $month->format('F Y') }}</span>
    <a href="{{ route('leave.calendar', array_merge(request()->only('department_id'), ['month' => $month->copy()->addMonth()->format('Y-m')])) }}"
       class="btn btn-outline-secondary"><i class="ti ti-chevron-right"></i></a>
    <a href="{{ route('leave.calendar', ['month' => now()->format('Y-m')]) }}" class="btn btn-outline-primary">Today</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
      <div class="col-md-4">
        <label class="form-label">Department</label>
        <select name="department_id" class="form-select" onchange="this.form.submit()">
          <option value="">All departments</option>
          @foreach($departments as $d)
            <option value="{{ $d->id }}" @selected((string)request('department_id')===(string)$d->id)>{{ $d->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-8 text-muted fs-13">
        {{ $total }} request(s) touching this month.
        <span class="badge bg-success ms-2">Approved</span>
        <span class="badge bg-warning text-dark">Pending</span>
        — pending is shown so cover is not approved twice onto the same day.
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-2">
    <div class="table-responsive">
      <table class="table table-bordered mb-0" style="table-layout:fixed">
        <thead>
          <tr class="text-center">
            @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $label)
              <th class="fs-13 text-muted">{{ $label }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @php $day = $gridStart->copy(); @endphp
          @while($day->lte($gridEnd))
          <tr>
            @for($i = 0; $i < 7; $i++)
              @php
                $date       = $day->toDateString();
                $inMonth    = $day->month === $month->month;
                $isWeekend  = in_array($day->dayOfWeek, $weekend, true);
                $isHoliday  = in_array($date, $holidays, true);
                $onLeave    = $byDate[$date] ?? [];
              @endphp
              <td class="align-top p-2 {{ $inMonth ? '' : 'bg-light' }}"
                  style="height:110px; {{ $date === $today ? 'box-shadow: inset 0 0 0 2px var(--bs-primary);' : '' }}">
                <div class="d-flex justify-content-between align-items-start mb-1">
                  <span class="fs-13 {{ $inMonth ? 'fw-semibold' : 'text-muted' }}">{{ $day->day }}</span>
                  @if($isHoliday)
                    <span class="badge bg-info-transparent fs-10">Holiday</span>
                  @elseif($isWeekend)
                    <span class="text-muted fs-10">Off</span>
                  @endif
                </div>

                @foreach(array_slice($onLeave, 0, 3) as $leave)
                  <div class="badge d-block text-truncate text-start mb-1
                              {{ $leave->status === 'approved' ? 'bg-success' : 'bg-warning text-dark' }}"
                       title="{{ $leave->employee?->full_name }} — {{ $leave->leaveType?->name }} ({{ $leave->status_label }})">
                    {{ $leave->employee?->first_name }} {{ substr($leave->employee?->last_name ?? '', 0, 1) }}
                  </div>
                @endforeach

                @if(count($onLeave) > 3)
                  {{-- Truncated rather than scrolled: the cell is the wrong place
                       to read a list of twelve people, and the count is the fact
                       that matters when you are looking for a clash. --}}
                  <div class="text-muted fs-11">+{{ count($onLeave) - 3 }} more</div>
                @endif
              </td>
              @php $day->addDay(); @endphp
            @endfor
          </tr>
          @endwhile
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
