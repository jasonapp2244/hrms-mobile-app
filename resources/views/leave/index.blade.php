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
			['Pending Approval', $stats['pending'], 'ti-clock-hour-4', 'warning'],
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

@if($stats['pending'] > 0)
	<div class="alert alert-warning">
		<i class="ti ti-alert-triangle me-1"></i>
		<strong>{{ $stats['pending'] }}</strong> request(s) are waiting for a decision.
		Approvals arrive in the next release — until then this register is view-only.
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
						<th>Submitted</th>
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
						</td>
						<td>{{ rtrim(rtrim(number_format($r->days, 1), '0'), '.') }}</td>
						<td>{{ $r->reason ? Str::limit($r->reason, 40) : '—' }}</td>
						<td><span class="badge bg-{{ $r->status_badge }}">{{ $r->status_label }}</span></td>
						<td>
							@if($r->approved_at)
								{{ $r->approver?->name ?? 'System' }}
								<div class="text-muted small">{{ $r->approved_at->format('M j, Y') }}</div>
							@else
								<span class="text-muted">—</span>
							@endif
						</td>
						<td class="text-muted small">{{ $r->created_at?->format('M j, Y') }}</td>
					</tr>
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
