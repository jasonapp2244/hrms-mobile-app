@extends('layouts.app')
@section('title', 'Attendance Logs')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance Logs</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">Attendance Logs</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('attendance.report') }}" class="btn btn-outline-secondary mb-2"><i class="ti ti-file-report me-1"></i>Report</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<!-- Filters -->
<div class="card">
	<div class="card-body">
		<form method="GET" action="{{ route('attendance.logs') }}" class="row g-3 align-items-end">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Date</label>
				<input type="date" name="date" class="form-control" value="{{ request('date') }}">
			</div>
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Office</label>
				<select name="office_id" class="form-select">
					<option value="">All Offices</option>
					@foreach($offices as $o)
						<option value="{{ $o->id }}" {{ request('office_id')==$o->id ? 'selected' : '' }}>{{ $o->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Type</label>
				<select name="type" class="form-select">
					<option value="">All</option>
					<option value="in" {{ request('type')=='in' ? 'selected' : '' }}>IN</option>
					<option value="out" {{ request('type')=='out' ? 'selected' : '' }}>OUT</option>
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Status</label>
				<select name="status" class="form-select">
					<option value="">All</option>
					<option value="ontime" {{ request('status')=='ontime' ? 'selected' : '' }}>On Time</option>
					<option value="late" {{ request('status')=='late' ? 'selected' : '' }}>Late</option>
					<option value="early_leave" {{ request('status')=='early_leave' ? 'selected' : '' }}>Early Leave</option>
					<option value="invalid" {{ request('status')=='invalid' ? 'selected' : '' }}>Invalid</option>
				</select>
			</div>
			<div class="col-md-2 col-sm-12 d-flex">
				<button type="submit" class="btn btn-primary me-2"><i class="ti ti-filter me-1"></i>Filter</button>
				<a href="{{ route('attendance.logs') }}" class="btn btn-light">Clear</a>
			</div>
		</form>
	</div>
</div>

<!-- Logs table -->
<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Employee Code</th>
						<th>Office</th>
						<th>Type</th>
						<th>Status</th>
						<th>Time</th>
						<th>Date</th>
						<th>Source</th>
					</tr>
				</thead>
				<tbody>
					@forelse($logs as $log)
					<tr>
						<td>{{ $log->employee->full_name ?? 'Unknown' }}</td>
						<td>{{ $log->employee->employee_code ?? '' }}</td>
						<td>{{ $log->office->name ?? '' }}</td>
						<td><span class="badge bg-{{ $log->type=='in'?'success':'secondary' }}">{{ strtoupper($log->type) }}</span></td>
						<td><span class="badge bg-{{ $log->status=='late'?'warning':($log->status=='ontime'?'success':'secondary') }}">{{ $log->status }}</span></td>
						<td>{{ $log->scanned_at->format('h:i A') }}</td>
						<td>{{ $log->work_date->format('m/d/Y') }}</td>
						<td>{{ $log->source }}</td>
					</tr>
					@empty
					<tr>
						<td colspan="8" class="text-center text-muted py-4">No attendance logs found.</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $logs->links() }}
	</div>
</div>
@endsection
