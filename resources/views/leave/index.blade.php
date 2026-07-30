@extends('layouts.app')
@section('title', 'Leave Requests')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Leave Requests</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Leave</li>
				<li class="breadcrumb-item active">Requests</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('leave-types.index') }}" class="btn btn-outline-secondary mb-2"><i class="ti ti-settings me-1"></i>Leave Types</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<!-- Tiles -->
<div class="row g-3 mb-3">
	@php
		$tiles = [
			['Awaiting Your Decision', $stats['awaiting_hr'], 'ti-clock-hour-4', 'warning'],
			['On Leave Today', $stats['on_leave'], 'ti-user-off', 'danger'],
			['Upcoming', $stats['upcoming'], 'ti-calendar-plus', 'info'],
			['Approved This Month', $stats['this_month'], 'ti-calendar-check', 'success'],
		];
	@endphp
	@foreach($tiles as [$label, $value, $icon, $tone])
	<div class="col-lg-3 col-sm-6">
		<div class="card h-100">
			<div class="card-body d-flex align-items-center">
				<span class="avatar avatar-lg bg-{{ $tone }}-transparent text-{{ $tone }} rounded-circle me-3">
					<i class="ti {{ $icon }} fs-20"></i>
				</span>
				<div>
					<p class="mb-1 text-muted">{{ $label }}</p>
					<h4 class="mb-0">{{ $value }}</h4>
				</div>
			</div>
		</div>
	</div>
	@endforeach
</div>

@if($stats['pending'] > $stats['awaiting_hr'])
	<div class="alert alert-info">
		<i class="ti ti-info-circle me-1"></i>
		<strong>{{ $stats['pending'] - $stats['awaiting_hr'] }}</strong> further request(s) are still
		with their line manager. They reach you once the manager passes them on — you can approve
		them early from the table below, which bypasses that step.
	</div>
@endif

<!-- Filters -->
<div class="card">
	<div class="card-body">
		<form method="GET" action="{{ route('leave.index') }}" class="row g-3 align-items-end">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Employee</label>
				<input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Name or code">
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Status</label>
				<select name="status" class="form-select">
					<option value="">All</option>
					@foreach(\App\Models\LeaveRequest::STATUSES as $key => $label)
						<option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Leave Type</label>
				<select name="leave_type_id" class="form-select">
					<option value="">All</option>
					@foreach($types as $t)
						<option value="{{ $t->id }}" {{ request('leave_type_id') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Department</label>
				<select name="department_id" class="form-select">
					<option value="">All</option>
					@foreach($departments as $d)
						<option value="{{ $d->id }}" {{ request('department_id') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Overlapping</label>
				<div class="d-flex gap-2">
					<input type="date" name="from" class="form-control" value="{{ request('from') }}">
					<input type="date" name="to" class="form-control" value="{{ request('to') }}">
				</div>
			</div>
			<div class="col-12 d-flex">
				<button type="submit" class="btn btn-primary me-2"><i class="ti ti-filter me-1"></i>Filter</button>
				<a href="{{ route('leave.index') }}" class="btn btn-light">Clear</a>
			</div>
		</form>
	</div>
</div>

<div class="card mt-3">
	<div class="card-header"><h5 class="mb-0">All Requests</h5></div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Department</th>
						<th>Leave Type</th>
						<th>Dates</th>
						<th>Days</th>
						<th>Reason</th>
						<th>Status</th>
						<th>Decided By</th>
						@can('approve-leave')<th class="text-end">Actions</th>@endcan
					</tr>
				</thead>
				<tbody>
					@forelse($requests as $r)
					<tr>
						<td>
							<strong>{{ $r->employee?->full_name ?? '—' }}</strong>
							<div class="text-muted small">{{ $r->employee?->employee_code }}</div>
						</td>
						<td>{{ $r->employee?->department?->name ?? '—' }}</td>
						<td>
							<span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:{{ $r->leaveType?->color }}"></span>
							{{ $r->leaveType?->name ?? '—' }}
							@if($r->is_half_day)
								<span class="badge bg-light text-dark ms-1">{{ $r->half_day_period === 'second_half' ? '2nd half' : '1st half' }}</span>
							@endif
						</td>
						<td>
							{{ $r->start_date->format('M j, Y') }}
							@if(! $r->start_date->isSameDay($r->end_date))
								<span class="text-muted">→</span> {{ $r->end_date->format('M j, Y') }}
							@endif
							<div class="text-muted small">submitted {{ $r->created_at?->format('M j, Y') }}</div>
						</td>
						<td>{{ rtrim(rtrim(number_format($r->days, 1), '0'), '.') }}</td>
						<td>{{ $r->reason ? Str::limit($r->reason, 40) : '—' }}</td>
						<td>
							@if($r->status === 'pending')
								<span class="badge bg-{{ $r->isAwaitingManager() ? 'secondary' : 'warning' }}">{{ $r->stage_label }}</span>
								@if($r->isAwaitingManager() && $r->employee?->manager)
									<div class="text-muted small">with {{ $r->employee->manager->full_name }}</div>
								@endif
							@else
								<span class="badge bg-{{ $r->status_badge }}">{{ $r->status_label }}</span>
							@endif
						</td>
						<td>
							@if($r->approved_at)
								{{ $r->approver?->name ?? 'System' }}
								<div class="text-muted small">{{ $r->approved_at->format('M j, Y') }}</div>
							@elseif($r->manager_approved_at)
								{{-- Not decided yet, but the manager step is on the record. --}}
								<span class="text-muted small">manager: {{ $r->managerApprover?->name ?? '—' }}</span>
							@else
								<span class="text-muted">—</span>
							@endif
						</td>
						@can('approve-leave')
						<td class="text-end text-nowrap">
							@if($r->isPending())
								<button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approve{{ $r->id }}">Approve</button>
								<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject{{ $r->id }}">Reject</button>
							@else
								<span class="text-muted small">—</span>
							@endif
						</td>
						@endcan
					</tr>

					@can('approve-leave')
					@if($r->isPending())
					<!-- Approve -->
					<div class="modal fade" id="approve{{ $r->id }}" tabindex="-1" aria-hidden="true">
						<div class="modal-dialog">
							<div class="modal-content">
								<form action="{{ route('leave.approve', $r) }}" method="POST">
									@csrf
									<div class="modal-header">
										<h5 class="modal-title">Approve leave</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										<p class="mb-2">
											<strong>{{ $r->employee?->full_name }}</strong> —
											{{ rtrim(rtrim(number_format($r->days, 1), '0'), '.') }} day(s) of
											{{ $r->leaveType?->name }},
											{{ $r->start_date->format('M j') }}@if(! $r->start_date->isSameDay($r->end_date)) → {{ $r->end_date->format('M j, Y') }}@else, {{ $r->start_date->format('Y') }}@endif.
										</p>
										@if($r->manager_note)
											<p class="text-muted small">Manager's note: {{ $r->manager_note }}</p>
										@endif
										@if($r->isAwaitingManager())
											<div class="alert alert-warning py-2 small">
												<i class="ti ti-alert-triangle me-1"></i>
												{{ $r->employee?->manager?->full_name ?? 'Their manager' }} has not reviewed
												this yet. Approving now skips the manager step.
											</div>
										@endif
										<p class="text-muted small mb-2">The days are deducted from their balance on approval.</p>
										<label class="form-label">Note <span class="text-muted">(optional)</span></label>
										<textarea name="decision_note" class="form-control" rows="2" maxlength="1000"></textarea>
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
										<button type="submit" class="btn btn-success">Approve Leave</button>
									</div>
								</form>
							</div>
						</div>
					</div>

					<!-- Reject -->
					<div class="modal fade" id="reject{{ $r->id }}" tabindex="-1" aria-hidden="true">
						<div class="modal-dialog">
							<div class="modal-content">
								<form action="{{ route('leave.reject', $r) }}" method="POST">
									@csrf
									<div class="modal-header">
										<h5 class="modal-title">Reject leave</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										<p class="mb-2"><strong>{{ $r->employee?->full_name }}</strong> — {{ $r->leaveType?->name }}</p>
										<label class="form-label">Reason <span class="text-danger">*</span></label>
										<textarea name="decision_note" class="form-control" rows="3" maxlength="1000" required
											placeholder="The employee sees this."></textarea>
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
										<button type="submit" class="btn btn-danger">Reject Request</button>
									</div>
								</form>
							</div>
						</div>
					</div>
					@endif
					@endcan
					@empty
					<tr><td colspan="9" class="text-center text-muted">No leave requests match these filters.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $requests->links() }}
	</div>
</div>
@endsection
