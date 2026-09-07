@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', 'My Team')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">My Team</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('manager.dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item active">My Team</li>
			</ol>
		</nav>
	</div>
</div>

<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-3 align-items-end">
			<div class="col-md-6">
				<label class="form-label">Search</label>
				<input type="search" name="q" value="{{ $search }}" class="form-control"
					placeholder="Name, employee code or department">
			</div>
			<div class="col-md-3">
				<button type="submit" class="btn btn-primary"><i class="ti ti-search me-1"></i>Search</button>
				@if($search !== '')
				<a href="{{ route('manager.team.index') }}" class="btn btn-outline-secondary ms-1">Clear</a>
				@endif
			</div>
			<div class="col-md-3 text-md-end">
				<span class="text-muted fs-13">
					{{ $rows->count() }} of {{ $total }} {{ \Illuminate\Support\Str::plural('person', $total) }}
				</span>
			</div>
		</form>
	</div>
</div>

<div class="card">
	<div class="card-body p-0">
		<div class="table-responsive">
			<table class="table table-hover mb-0">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Department</th>
						<th>Site</th>
						<th>Today</th>
						<th>In</th>
						<th>Out</th>
						<th>Worked</th>
						<th>Shift</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
					@php $employee = $row['employee']; @endphp
					<tr>
						<td>
							<a href="{{ route('manager.team.show', $employee) }}" class="fw-medium">{{ $employee->full_name }}</a>
							<div class="fs-12 text-muted">{{ $employee->employee_code }}</div>
						</td>
						<td>{{ $employee->department?->name ?? '—' }}</td>
						<td>{{ $employee->office?->name ?? '—' }}</td>
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
						<td colspan="8" class="text-center text-muted py-4">
							@if($total === 0)
								Nobody reports to you yet. HR sets the line manager on an employee's record.
							@else
								No one on your team matches that search.
							@endif
						</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
