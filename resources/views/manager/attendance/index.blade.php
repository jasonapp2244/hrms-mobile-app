@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', 'Team Attendance')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Team Attendance</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('manager.dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item active">{{ \Carbon\Carbon::parse($date)->format('l j F Y') }}</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('manager.attendance.logs') }}" class="btn btn-outline-secondary mb-2">
			<i class="ti ti-list me-1"></i>Punch log
		</a>
	</div>
</div>

<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-3 align-items-end">
			<div class="col-md-3">
				<label class="form-label">Date</label>
				{{-- max stops somebody asking about tomorrow, which the controller
					 clamps anyway — a day that has not happened cannot have an
					 absence, and reporting one would be a claim nobody can answer. --}}
				<input type="date" name="date" value="{{ $date }}" max="{{ $today }}" class="form-control">
			</div>
			<div class="col-md-3">
				<button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i>Show</button>
				@if($date !== $today)
				<a href="{{ route('manager.attendance.index') }}" class="btn btn-outline-secondary ms-1">Today</a>
				@endif
			</div>
		</form>
	</div>
</div>

@php
	$tiles = [
		['label' => 'Team',         'value' => $summary['total']],
		['label' => 'On the clock', 'value' => $summary['in_now']],
		['label' => 'Present',      'value' => $summary['present']],
		['label' => 'Late',         'value' => $summary['late']],
		['label' => 'On leave',     'value' => $summary['on_leave']],
		['label' => 'Unaccounted',  'value' => $summary['absent']],
		['label' => 'Off',          'value' => $summary['off']],
	];
@endphp

<div class="row">
	@foreach($tiles as $tile)
	<div class="col-xl col-md-3 col-6 mb-3">
		<div class="card"><div class="card-body text-center">
			<h3 class="mb-0">{{ $tile['value'] }}</h3>
			<p class="text-muted mb-0 fs-13">{{ $tile['label'] }}</p>
		</div></div>
	</div>
	@endforeach
</div>

<div class="card">
	<div class="card-body p-0">
		<div class="table-responsive">
			<table class="table table-hover mb-0">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Status</th>
						<th>First in</th>
						<th>Last out</th>
						<th>Worked</th>
						<th>Shift that day</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
					<tr>
						<td>
							<a href="{{ route('manager.team.show', $row['employee']) }}">{{ $row['employee']->full_name }}</a>
							<div class="fs-12 text-muted">{{ $row['employee']->employee_code }}</div>
						</td>
						<td>@include('manager.partials.status-badge', ['status' => $row['status'], 'late' => $row['late']])</td>
						<td>{{ $row['first_in'] ? $row['first_in']->timezone($timezone)->format('h:i A') : '—' }}</td>
						<td>{{ $row['last_out'] ? $row['last_out']->timezone($timezone)->format('h:i A') : '—' }}</td>
						<td>
							@if($row['worked_minutes'] > 0)
								{{ intdiv($row['worked_minutes'], 60) }}h {{ $row['worked_minutes'] % 60 }}m
							@else
								—
							@endif
						</td>
						<td>
							@if($row['shift'])
								{{ $row['shift']->name }}
								<div class="fs-12 text-muted">{{ $row['shift']->start_time }} – {{ $row['shift']->end_time }}</div>
							@else
								<span class="text-muted">—</span>
							@endif
						</td>
					</tr>
					@empty
					<tr>
						<td colspan="6" class="text-center text-muted py-4">
							Nobody reports to you yet. HR sets the line manager on an employee's record.
						</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
