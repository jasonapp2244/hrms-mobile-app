@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', 'Team Dashboard')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Good day, {{ $manager->first_name }}</h2>
		<p class="text-muted mb-0">Your team on {{ \Carbon\Carbon::parse($today)->format('l j F Y') }}</p>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('manager.attendance.index') }}" class="btn btn-outline-secondary me-2 mb-2">
			<i class="ti ti-calendar-check me-1"></i>Today in detail
		</a>
		<a href="{{ route('employee.dashboard') }}" class="btn btn-primary mb-2">
			<i class="ti ti-clock-play me-1"></i>My own clock
		</a>
	</div>
</div>

@if($team->isEmpty())
	{{-- The honest empty state. Holding the manager role grants the area;
		 employees.manager_id decides the scope, and the two are set in different
		 places, so "role but no reports" is a real configuration rather than an
		 error. Saying so plainly is what stops it being reported as a bug. --}}
	<div class="card">
		<div class="card-body text-center py-5">
			<i class="ti ti-users-off fs-1 text-muted d-block mb-3"></i>
			<h5 class="mb-1">Nobody reports to you yet</h5>
			<p class="text-muted mb-0">
				Your account has the manager role, but no employee has you set as their
				line manager. HR sets that on the employee's record.
			</p>
		</div>
	</div>
@else

@php
	$tiles = [
		['label' => 'Team',          'value' => $summary['total'],    'icon' => 'ti-users-group',   'tone' => 'secondary'],
		['label' => 'On the clock',  'value' => $summary['in_now'],   'icon' => 'ti-player-play',   'tone' => 'success'],
		['label' => 'Present today', 'value' => $summary['present'],  'icon' => 'ti-user-check',    'tone' => 'info'],
		['label' => 'Late',          'value' => $summary['late'],     'icon' => 'ti-alarm',         'tone' => 'warning'],
		['label' => 'On leave',      'value' => $summary['on_leave'], 'icon' => 'ti-beach',         'tone' => 'primary'],
		['label' => 'Unaccounted',   'value' => $summary['absent'],   'icon' => 'ti-user-question', 'tone' => 'danger'],
	];
@endphp

<div class="row">
	@foreach($tiles as $tile)
	<div class="col-xl-2 col-md-4 col-6 mb-3">
		<div class="card h-100">
			<div class="card-body text-center">
				<i class="ti {{ $tile['icon'] }} fs-3 text-{{ $tile['tone'] }} d-block mb-1"></i>
				<h3 class="mb-0">{{ $tile['value'] }}</h3>
				<p class="text-muted mb-0 fs-13">{{ $tile['label'] }}</p>
			</div>
		</div>
	</div>
	@endforeach
</div>

<div class="row">
	{{-- Waiting on this manager. First, because it is the only panel about
		 something they have to do rather than something to know. --}}
	<div class="col-xl-6 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0"><i class="ti ti-checklist me-1"></i>Waiting on you</h5>
				@can('approve-leave')
				<a href="{{ route('manager.approvals.index') }}" class="btn btn-sm btn-outline-primary">Open inbox</a>
				@endcan
			</div>
			<div class="card-body">
				@if($pendingLeave->isEmpty() && $pendingSwaps->isEmpty())
					<p class="text-muted mb-0">Nothing needs a decision from you.</p>
				@else
					@foreach($pendingLeave as $request)
					<div class="d-flex align-items-center justify-content-between border-bottom py-2">
						<div>
							<span class="fw-medium">{{ $request->employee?->full_name }}</span>
							<span class="text-muted fs-13">
								&middot; {{ $request->leaveType?->name }}
								&middot; {{ $request->start_date->format('j M') }}@if($request->start_date->ne($request->end_date)) &ndash; {{ $request->end_date->format('j M') }}@endif
							</span>
						</div>
						<span class="badge bg-warning">Leave</span>
					</div>
					@endforeach

					@foreach($pendingSwaps as $swap)
					<div class="d-flex align-items-center justify-content-between border-bottom py-2">
						<div>
							<span class="fw-medium">{{ $swap->requester?->full_name }}</span>
							<span class="text-muted fs-13">&middot; swap with {{ $swap->target?->full_name }}</span>
						</div>
						<span class="badge bg-info">Swap</span>
					</div>
					@endforeach
				@endif
			</div>
		</div>
	</div>

	{{-- Shifts nobody clocked out of. Today is excluded on purpose: an open
		 punch mid-shift is normal, and flagging it would cry wolf every morning. --}}
	<div class="col-xl-6 mb-3">
		<div class="card h-100">
			<div class="card-header">
				<h5 class="mb-0"><i class="ti ti-clock-exclamation me-1"></i>Missing clock-outs</h5>
			</div>
			<div class="card-body">
				@forelse($missingCheckouts as $log)
				<div class="d-flex align-items-center justify-content-between border-bottom py-2">
					<div>
						<span class="fw-medium">{{ $log->employee?->full_name }}</span>
						<span class="text-muted fs-13">&middot; in at {{ $log->scanned_at->timezone($timezone)->format('h:i A') }}</span>
					</div>
					<span class="text-muted fs-13">{{ $log->work_date->format('D j M') }}</span>
				</div>
				@empty
				<p class="text-muted mb-0">Every finished shift was clocked out of.</p>
				@endforelse
			</div>
		</div>
	</div>
</div>

{{-- Coverage for the week ahead, published days only. --}}
<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0"><i class="ti ti-calendar-time me-1"></i>Coverage, next 7 days</h5>
		<a href="{{ route('manager.schedule.index') }}" class="btn btn-sm btn-outline-secondary">Full schedule</a>
	</div>
	<div class="card-body">
		<div class="row g-2">
			@foreach($coverage as $day)
			<div class="col">
				<div class="border rounded text-center p-2 h-100 {{ $day['holiday'] ? 'bg-light' : '' }}">
					<div class="fs-13 text-muted">{{ $day['label'] }}</div>
					<div class="fs-12 text-muted mb-1">{{ $day['day'] }}</div>
					@if($day['holiday'])
						<span class="badge bg-secondary">Holiday</span>
					@elseif(! $day['is_working'])
						<span class="badge bg-light text-muted">Weekend</span>
					@elseif($day['unplanned'])
						{{-- A working day with nothing published is a real gap, and is
							 named as one rather than drawn as a zero that looks like a
							 decision somebody made. --}}
						<span class="badge bg-warning">Not planned</span>
					@else
						<div class="fs-5 fw-semibold">{{ $day['rostered'] }}</div>
						<div class="fs-12 text-muted">on shift</div>
						@if($day['on_leave'] > 0)
						<div class="fs-12 text-primary">{{ $day['on_leave'] }} on leave</div>
						@endif
					@endif
				</div>
			</div>
			@endforeach
		</div>
	</div>
</div>

<div class="row">
	<div class="col-xl-7 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0"><i class="ti ti-users me-1"></i>Your team today</h5>
				<a href="{{ route('manager.team.index') }}" class="btn btn-sm btn-outline-secondary">All details</a>
			</div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table table-hover mb-0">
						<thead>
							<tr>
								<th>Employee</th>
								<th>Status</th>
								<th>In</th>
								<th>Out</th>
							</tr>
						</thead>
						<tbody>
							@foreach($rows as $row)
							<tr>
								<td>
									<a href="{{ route('manager.team.show', $row['employee']) }}">{{ $row['employee']->full_name }}</a>
									<div class="fs-12 text-muted">{{ $row['employee']->employee_code }}</div>
								</td>
								<td>@include('manager.partials.status-badge', ['status' => $row['status'], 'late' => $row['late']])</td>
								<td>{{ $row['first_in'] ? $row['first_in']->timezone($timezone)->format('h:i A') : '—' }}</td>
								<td>{{ $row['last_out'] ? $row['last_out']->timezone($timezone)->format('h:i A') : '—' }}</td>
							</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header">
				<h5 class="mb-0"><i class="ti ti-activity me-1"></i>Recent punches</h5>
			</div>
			<div class="card-body">
				@forelse($recent as $log)
				<div class="d-flex align-items-center justify-content-between border-bottom py-2">
					<div>
						<span class="fw-medium">{{ $log->employee?->full_name }}</span>
						<span class="badge bg-{{ $log->type === 'in' ? 'success' : ($log->type === 'out' ? 'secondary' : 'info') }} ms-1">
							{{ str_replace('_', ' ', $log->type) }}
						</span>
					</div>
					<span class="text-muted fs-13">{{ $log->scanned_at->timezone($timezone)->format('D h:i A') }}</span>
				</div>
				@empty
				<p class="text-muted mb-0">No punches recorded yet.</p>
				@endforelse
			</div>
		</div>
	</div>
</div>

@endif
@endsection
