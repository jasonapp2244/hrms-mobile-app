@extends('layouts.app')
@section('title', 'Attendance Overview')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance Overview</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">Attendance Overview</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('attendance.kiosk') }}" class="btn btn-primary me-2 mb-2"><i class="ti ti-qrcode me-1"></i>QR Kiosk</a>
		<a href="{{ route('attendance.logs') }}" class="btn btn-outline-secondary mb-2"><i class="ti ti-list me-1"></i>All Logs</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<!-- Stat tiles -->
<div class="row">
	@php
		$tiles = [
			['label'=>'Present','value'=>$summary['present'],'icon'=>'ti-user-check','bg'=>'success'],
			['label'=>'Late','value'=>$summary['late'],'icon'=>'ti-clock-exclamation','bg'=>'warning'],
			['label'=>'Absent','value'=>$summary['absent'],'icon'=>'ti-user-x','bg'=>'danger'],
			['label'=>'Total','value'=>$summary['total'],'icon'=>'ti-users-group','bg'=>'primary'],
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

<!-- Recent Scans -->
<div class="card">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0">Recent Scans</h5>
		<a href="{{ route('attendance.logs') }}" class="btn btn-sm btn-light">View all</a>
	</div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Office</th>
						<th>Type</th>
						<th>Status</th>
						<th>Time</th>
						<th>Date</th>
					</tr>
				</thead>
				<tbody>
					@forelse($recent as $log)
					<tr>
						<td>{{ $log->employee->full_name ?? 'Unknown' }}</td>
						<td>{{ $log->office->name ?? '' }}</td>
						<td><span class="badge bg-{{ $log->type=='in'?'success':'secondary' }}">{{ strtoupper($log->type) }}</span></td>
						<td><span class="badge bg-{{ $log->status=='late'?'warning':($log->status=='ontime'?'success':'secondary') }}">{{ $log->status }}</span></td>
						<td>{{ $log->scanned_at->format('h:i A') }}</td>
						<td>{{ $log->work_date->format('m/d/Y') }}</td>
					</tr>
					@empty
					<tr>
						<td colspan="6" class="text-center text-muted py-4">No recent scans found.</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
