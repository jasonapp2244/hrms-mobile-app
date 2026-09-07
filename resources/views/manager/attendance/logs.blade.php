@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', 'Team Punch Log')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Punch Log</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('manager.dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item"><a href="{{ route('manager.attendance.index') }}">Attendance</a></li>
				<li class="breadcrumb-item active">Punch Log</li>
			</ol>
		</nav>
	</div>
</div>

{{-- Read-only, and deliberately so. Keying a punch in by hand and striking one
	 out are both `manage-attendance`, which this role does not hold: attendance
	 is append-only and every write records an actor. A wrong punch goes back
	 through the employee's regularisation request or HR's correction screen,
	 both of which leave a trail this page would not. --}}
<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-3 align-items-end">
			<div class="col-md-3">
				<label class="form-label">Employee</label>
				<select name="employee_id" class="form-select">
					<option value="">Everyone on my team</option>
					@foreach($team as $member)
					<option value="{{ $member->id }}" {{ (string) request('employee_id') === (string) $member->id ? 'selected' : '' }}>
						{{ $member->full_name }}
					</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2">
				<label class="form-label">Date</label>
				<input type="date" name="date" value="{{ request('date') }}" class="form-control">
			</div>
			<div class="col-md-2">
				<label class="form-label">Type</label>
				<select name="type" class="form-select">
					<option value="">Any</option>
					@foreach(['in' => 'In', 'out' => 'Out', 'break_start' => 'Break start', 'break_end' => 'Break end'] as $value => $label)
					<option value="{{ $value }}" {{ request('type') === $value ? 'selected' : '' }}>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2">
				<label class="form-label">Status</label>
				<select name="status" class="form-select">
					<option value="">Any</option>
					@foreach(['ontime' => 'On time', 'late' => 'Late', 'early' => 'Early'] as $value => $label)
					<option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-3">
				<button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i>Filter</button>
				<a href="{{ route('manager.attendance.logs') }}" class="btn btn-outline-secondary ms-1">Clear</a>
			</div>
		</form>
	</div>
</div>

<div class="card">
	<div class="card-body p-0">
		<div class="table-responsive">
			<table class="table table-hover mb-0">
				<thead>
					<tr><th>Employee</th><th>Work date</th><th>Type</th><th>Time</th><th>Status</th><th>Site</th><th>Source</th></tr>
				</thead>
				<tbody>
					@forelse($logs as $log)
					<tr>
						<td>
							<a href="{{ route('manager.team.show', $log->employee_id) }}">{{ $log->employee?->full_name }}</a>
							<div class="fs-12 text-muted">{{ $log->employee?->employee_code }}</div>
						</td>
						<td>{{ $log->work_date->format('D j M Y') }}</td>
						<td>{{ str_replace('_', ' ', $log->type) }}</td>
						<td>{{ $log->scanned_at->timezone($manager->company?->tz() ?? config('app.timezone'))->format('h:i A') }}</td>
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
						<td class="text-muted fs-13">{{ $log->source ?? '—' }}</td>
					</tr>
					@empty
					<tr><td colspan="7" class="text-center text-muted py-4">No punches match those filters.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
	@if($logs->hasPages())
	<div class="card-footer">{{ $logs->links() }}</div>
	@endif
</div>
@endsection
