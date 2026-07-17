@extends('layouts.app')
@section('title', 'Attendance Report')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance Report</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">Attendance Report</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<button onclick="window.print()" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-printer me-1"></i>Print</button>
		<a href="{{ route('attendance.report.pdf', request()->only('from','to','office_id')) }}" class="btn btn-danger me-2 mb-2"><i class="ti ti-file-type-pdf me-1"></i>Export PDF</a>
		<a href="{{ route('attendance.report.excel', request()->only('from','to','office_id')) }}" class="btn btn-success mb-2"><i class="ti ti-file-spreadsheet me-1"></i>Export Excel</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<!-- Filters -->
<div class="card">
	<div class="card-body">
		<form method="GET" action="{{ route('attendance.report') }}" class="row g-3 align-items-end">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">From</label>
				<input type="date" name="from" class="form-control" value="{{ $from }}">
			</div>
			<div class="col-md-3 col-sm-6">
				<label class="form-label">To</label>
				<input type="date" name="to" class="form-control" value="{{ $to }}">
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
			<div class="col-md-3 col-sm-6">
				<button type="submit" class="btn btn-primary"><i class="ti ti-file-report me-1"></i>Generate</button>
			</div>
		</form>
	</div>
</div>

<!-- Stat tiles -->
<div class="row">
	@php
		$tiles = [
			['label'=>'Total Scans','value'=>$stats['total_scans'],'icon'=>'ti-scan','bg'=>'primary'],
			['label'=>'On Time','value'=>$stats['ontime'],'icon'=>'ti-user-check','bg'=>'success'],
			['label'=>'Late','value'=>$stats['late'],'icon'=>'ti-clock-exclamation','bg'=>'warning'],
			['label'=>'Days','value'=>$stats['days'],'icon'=>'ti-calendar','bg'=>'info'],
		];
	@endphp
	@foreach($tiles as $t)
	<div class="col-xl-3 col-sm-6 mb-3">
		<div class="card h-100">
			<div class="card-body text-center">
				<span class="avatar avatar-lg bg-{{ $t['bg'] }}-transparent text-{{ $t['bg'] }} rounded-circle mb-2">
					<i class="ti {{ $t['icon'] }} fs-24"></i>
				</span>
				<h3 class="mb-0">{{ $t['value'] }}</h3>
				<p class="text-muted mb-0 fs-13">{{ $t['label'] }}</p>
			</div>
		</div>
	</div>
	@endforeach
</div>

<!-- Report table -->
<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Office</th>
						<th>Type</th>
						<th>Status</th>
						<th>Date</th>
						<th>Time</th>
					</tr>
				</thead>
				<tbody>
					@forelse($logs as $log)
					<tr>
						<td>{{ $log->employee->full_name ?? 'Unknown' }}</td>
						<td>{{ $log->office->name ?? '' }}</td>
						<td><span class="badge bg-{{ $log->type=='in'?'success':'secondary' }}">{{ strtoupper($log->type) }}</span></td>
						<td><span class="badge bg-{{ $log->status=='late'?'warning':($log->status=='ontime'?'success':'secondary') }}">{{ $log->status }}</span></td>
						<td>{{ $log->work_date->format('m/d/Y') }}</td>
						<td>{{ $log->scanned_at->format('h:i A') }}</td>
					</tr>
					@empty
					<tr>
						<td colspan="6" class="text-center text-muted py-4">No attendance records found for this range.</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
