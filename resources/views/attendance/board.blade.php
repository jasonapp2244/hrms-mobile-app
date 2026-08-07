@extends('layouts.app')
@section('title','Who Is In')

@php
	$in      = $board['in'];
	$left    = $board['left'];
	$notIn   = $board['not_in'];
	$onLeave = $board['on_leave'];
	$asOf    = $board['as_of'];
	$onBreak = collect($in)->where('on_break', true)->count();
@endphp

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Who Is In</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('attendance.index') }}">Attendance</a></li>
      <li class="breadcrumb-item active">Live Board</li>
    </ol></nav></div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <form method="GET">
      <select name="office_id" class="form-select" onchange="this.form.submit()">
        <option value="">All offices</option>
        @foreach($offices as $o)
          <option value="{{ $o->id }}" @selected((string)request('office_id')===(string)$o->id)>{{ $o->name }}</option>
        @endforeach
      </select>
    </form>
    <span class="text-muted fs-13 text-nowrap">As of {{ $asOf->format('H:i') }}</span>
  </div>
</div>

<div class="row">
  <div class="col-md-3 col-6 mb-3">
    <div class="card border-success"><div class="card-body text-center">
      <h2 class="mb-0 text-success">{{ count($in) }}</h2>
      {{-- The space before @if matters: Blade does not recognise a directive
           glued to the preceding word, so "clock@if(...)" compiles the @endif
           without its @if and the view will not parse. --}}
      <p class="text-muted mb-0 fs-13">On the clock @if($onBreak) · {{ $onBreak }} on break @endif</p>
    </div></div>
  </div>
  <div class="col-md-3 col-6 mb-3">
    <div class="card"><div class="card-body text-center">
      <h2 class="mb-0">{{ count($left) }}</h2>
      <p class="text-muted mb-0 fs-13">Been in and left</p>
    </div></div>
  </div>
  <div class="col-md-3 col-6 mb-3">
    <div class="card"><div class="card-body text-center">
      <h2 class="mb-0 text-info">{{ count($onLeave) }}</h2>
      <p class="text-muted mb-0 fs-13">On approved leave</p>
    </div></div>
  </div>
  <div class="col-md-3 col-6 mb-3">
    <div class="card {{ count($notIn) ? 'border-warning' : '' }}"><div class="card-body text-center">
      <h2 class="mb-0 {{ count($notIn) ? 'text-warning' : '' }}">{{ count($notIn) }}</h2>
      <p class="text-muted mb-0 fs-13">Not accounted for</p>
    </div></div>
  </div>
</div>

<div class="row">
  <div class="col-lg-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><h5 class="mb-0"><span class="badge bg-success me-2">{{ count($in) }}</span>On the clock</h5></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @forelse($in as $row)
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <a href="{{ route('employees.show', $row['employee']) }}">{{ $row['employee']->full_name }}</a>
              <div class="text-muted fs-12">{{ $row['employee']->department->name ?? '—' }}</div>
            </div>
            <div class="text-end">
              @if($row['on_break'])
                <span class="badge bg-warning text-dark">On break</span>
              @endif
              <div class="fs-13 text-muted">
                since {{ $row['since']?->format('H:i') ?? '—' }}
              </div>
            </div>
          </li>
          @empty
          <li class="list-group-item text-center text-muted py-4">Nobody is clocked in.</li>
          @endforelse
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-6 mb-3">
    <div class="card h-100">
      <div class="card-header">
        <h5 class="mb-0"><span class="badge bg-warning text-dark me-2">{{ count($notIn) }}</span>Not accounted for</h5>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @forelse($notIn as $row)
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <a href="{{ route('employees.show', $row['employee']) }}">{{ $row['employee']->full_name }}</a>
              <div class="text-muted fs-12">{{ $row['employee']->department->name ?? '—' }}</div>
            </div>
            <span class="text-muted fs-13">No punch today</span>
          </li>
          @empty
          <li class="list-group-item text-center text-muted py-4">Everybody is accounted for. 🎉</li>
          @endforelse
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Been in and left ({{ count($left) }})</h6></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @forelse($left as $row)
          <li class="list-group-item d-flex justify-content-between">
            <span>{{ $row['employee']->full_name }}</span>
            <span class="text-muted fs-13">{{ $row['since']?->format('H:i') }} – {{ $row['until']?->format('H:i') }}</span>
          </li>
          @empty
          <li class="list-group-item text-center text-muted py-3">Nobody has left yet.</li>
          @endforelse
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">On approved leave ({{ count($onLeave) }})</h6></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @forelse($onLeave as $row)
          <li class="list-group-item d-flex justify-content-between">
            <span>{{ $row['employee']->full_name }}</span>
            <span class="badge bg-info-transparent">{{ $row['leave']?->leaveType?->name ?? 'Leave' }}</span>
          </li>
          @empty
          <li class="list-group-item text-center text-muted py-3">Nobody is on leave today.</li>
          @endforelse
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
	// Polled, not pushed. The number changes a few times a minute at most, and a
	// reload every 60 seconds costs one query where a socket costs a process to
	// run for the life of the product. Paused when the tab is hidden so a board
	// left open on a spare monitor overnight is not still polling at 3am.
	(function () {
		var timer = null;

		function start() { timer = setTimeout(function () { location.reload(); }, 60000); }
		function stop() { if (timer) { clearTimeout(timer); timer = null; } }

		document.addEventListener('visibilitychange', function () {
			document.hidden ? stop() : start();
		});

		if (!document.hidden) start();
	})();
</script>
@endsection
