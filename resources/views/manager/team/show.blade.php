@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', $employee->full_name)

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">{{ $employee->full_name }}</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('manager.dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item"><a href="{{ route('manager.team.index') }}">My Team</a></li>
				<li class="breadcrumb-item active">{{ $employee->employee_code }}</li>
			</ol>
		</nav>
	</div>
</div>

<div class="row">
	<div class="col-xl-4 mb-3">
		<div class="card h-100">
			<div class="card-header"><h5 class="mb-0">Employment</h5></div>
			<div class="card-body">
				{{-- Work-facing facts only. Date of birth, national ID, home
					 address, personal email and the emergency contact all sit on
					 the HR record behind `manage-employees`, which this role does
					 not hold — a supervisor needs to know who is on shift, not
					 somebody's passport number. --}}
				<dl class="row mb-0">
					<dt class="col-5 fw-normal text-muted">Employee code</dt>
					<dd class="col-7">{{ $employee->employee_code ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Status</dt>
					<dd class="col-7">
						<span class="badge bg-{{ $employee->status === 'active' ? 'success' : 'secondary' }}">
							{{ ucfirst($employee->status) }}
						</span>
					</dd>

					<dt class="col-5 fw-normal text-muted">Department</dt>
					<dd class="col-7">{{ $employee->department?->name ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Job title</dt>
					<dd class="col-7">{{ $employee->designation?->name ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Site</dt>
					<dd class="col-7">{{ $employee->office?->name ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Work mode</dt>
					<dd class="col-7">{{ \App\Models\Employee::WORK_MODES[$employee->work_mode] ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Started</dt>
					<dd class="col-7">{{ $employee->hire_date?->format('j M Y') ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Standing shift</dt>
					<dd class="col-7">
						@if($employee->shift)
							{{ $employee->shift->name }}
							<div class="fs-12 text-muted">{{ $employee->shift->start_time }} – {{ $employee->shift->end_time }}</div>
						@else
							—
						@endif
					</dd>

					<dt class="col-5 fw-normal text-muted">Work phone</dt>
					<dd class="col-7">{{ $employee->phone ?? '—' }}</dd>

					<dt class="col-5 fw-normal text-muted">Work email</dt>
					<dd class="col-7">{{ $employee->email ?? '—' }}</dd>
				</dl>
			</div>
		</div>
	</div>

	<div class="col-xl-8 mb-3">
		<div class="card mb-3">
			<div class="card-header"><h5 class="mb-0">Leave balances</h5></div>
			<div class="card-body">
				<div class="row g-2">
					{{-- balanceSummary returns ['type' => LeaveType, 'balance' =>
						 LeaveBalance] per active type, and `available` is the
						 accessor — entitlement less what is already taken or
						 pending, which is the figure that decides whether the
						 request in the inbox can actually be granted. --}}
					@forelse($balances as $row)
					<div class="col-md-4 col-6">
						<div class="border rounded p-2 text-center">
							<div class="fs-13 text-muted">{{ $row['type']->name }}</div>
							<div class="fs-5 fw-semibold">{{ rtrim(rtrim(number_format($row['balance']->available, 1), '0'), '.') }}</div>
							<div class="fs-12 text-muted">days left</div>
						</div>
					</div>
					@empty
					<div class="col-12"><p class="text-muted mb-0">No balances have been raised for this year.</p></div>
					@endforelse
				</div>
			</div>
		</div>

		<div class="card">
			<div class="card-header"><h5 class="mb-0">Upcoming published shifts</h5></div>
			<div class="card-body">
				@forelse($schedule as $assignment)
				<div class="d-flex align-items-center justify-content-between border-bottom py-2">
					<span>{{ $assignment->date->format('D j M') }}</span>
					<span>
						@if($assignment->is_day_off)
							<span class="badge bg-secondary">Day off</span>
						@elseif($assignment->shift)
							{{ $assignment->shift->name }}
							<span class="text-muted fs-13">{{ $assignment->shift->start_time }} – {{ $assignment->shift->end_time }}</span>
						@else
							<span class="text-muted">—</span>
						@endif
					</span>
				</div>
				@empty
				{{-- Published only. A draft roster is invisible here for the same
					 reason it is invisible to the employee: the publish step exists
					 so nobody is told to come in on a day still being moved around. --}}
				<p class="text-muted mb-0">Nothing published for the next fortnight.</p>
				@endforelse
			</div>
		</div>
	</div>
</div>

<div class="row">
	<div class="col-xl-7 mb-3">
		<div class="card h-100">
			<div class="card-header">
				<h5 class="mb-0">Attendance</h5>
				<span class="text-muted fs-13">
					{{ \Carbon\Carbon::parse($from)->format('j M') }} – {{ \Carbon\Carbon::parse($today)->format('j M Y') }}
				</span>
			</div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table table-hover mb-0">
						<thead>
							<tr><th>Date</th><th>Type</th><th>Time</th><th>Status</th><th>Site</th></tr>
						</thead>
						<tbody>
							@forelse($logs as $log)
							<tr>
								<td>{{ $log->work_date->format('D j M') }}</td>
								<td>{{ str_replace('_', ' ', $log->type) }}</td>
								<td>{{ $log->scanned_at->timezone($timezone)->format('h:i A') }}</td>
								<td>
									@if($log->status === 'late')
										<span class="badge bg-warning">Late</span>
									@elseif($log->status)
										<span class="text-muted fs-13">{{ ucfirst($log->status) }}</span>
									@else
										—
									@endif
								</td>
								<td>{{ $log->office?->name ?? '—' }}</td>
							</tr>
							@empty
							<tr><td colspan="5" class="text-center text-muted py-4">No punches in this period.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header"><h5 class="mb-0">Leave history</h5></div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table table-hover mb-0">
						<thead>
							<tr><th>Dates</th><th>Type</th><th>Status</th></tr>
						</thead>
						<tbody>
							@forelse($leave as $request)
							<tr>
								<td>
									{{ $request->start_date->format('j M') }}
									@if($request->start_date->ne($request->end_date))
										&ndash; {{ $request->end_date->format('j M') }}
									@endif
								</td>
								<td>{{ $request->leaveType?->name ?? '—' }}</td>
								<td>
									<span class="badge bg-{{ \App\Models\LeaveRequest::STATUS_BADGES[$request->status] ?? 'secondary' }}">
										{{ \App\Models\LeaveRequest::STATUSES[$request->status] ?? ucfirst($request->status) }}
									</span>
								</td>
							</tr>
							@empty
							<tr><td colspan="3" class="text-center text-muted py-4">No leave on record.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>
@endsection
