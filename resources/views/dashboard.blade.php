@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Dashboard</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item active" aria-current="page">Admin Dashboard</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex my-xl-auto right-content align-items-center flex-wrap">
		<a href="{{ route('attendance.logs') }}" class="btn btn-primary me-2 mb-2"><i class="ti ti-list-check me-1"></i>Attendance Logs</a>
		<a href="{{ route('attendance.report') }}" class="btn btn-outline-secondary mb-2"><i class="ti ti-file-report me-1"></i>Reports</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<!-- Stat tiles -->
<div class="row">
	@php
		$tiles = [
			['label'=>'Total Employees','value'=>$stats['employees'],'icon'=>'ti-users-group','bg'=>'primary'],
			['label'=>'Present Today','value'=>$stats['present'],'icon'=>'ti-user-check','bg'=>'success'],
			['label'=>'Late Today','value'=>$stats['late'],'icon'=>'ti-clock-exclamation','bg'=>'warning'],
			['label'=>'Absent Today','value'=>$stats['absent'],'icon'=>'ti-user-x','bg'=>'danger'],
			['label'=>'Departments','value'=>$stats['departments'],'icon'=>'ti-building','bg'=>'info'],
			['label'=>'Offices','value'=>$stats['offices'],'icon'=>'ti-building-community','bg'=>'dark'],
		];
	@endphp
	@foreach($tiles as $t)
	<div class="col-xl-2 col-sm-4 col-6 mb-3">
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

<div class="row">
	<!-- Trend chart -->
	<div class="col-xl-7 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0">Attendance Trend — Last 7 Days</h5>
			</div>
			<div class="card-body">
				<div id="attendance-trend"></div>
			</div>
		</div>
	</div>

	<!-- Recent feed -->
	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0">Recent Activity</h5>
				<a href="{{ route('attendance.logs') }}" class="btn btn-sm btn-light">View all</a>
			</div>
			<div class="card-body p-0" style="max-height:340px;overflow:auto">
				<ul class="list-group list-group-flush">
					@forelse($recent as $log)
					<li class="list-group-item d-flex align-items-center justify-content-between">
						<div class="d-flex align-items-center">
							<span class="avatar avatar-sm bg-{{ $log->type=='in'?'success':'secondary' }}-transparent text-{{ $log->type=='in'?'success':'secondary' }} rounded-circle me-2">
								<i class="ti {{ $log->type=='in'?'ti-login-2':'ti-logout-2' }}"></i>
							</span>
							<div>
								<h6 class="mb-0 fs-14">{{ $log->employee->full_name ?? 'Unknown' }}</h6>
								<small class="text-muted">{{ strtoupper($log->type) }} · {{ $log->office->name ?? '' }}</small>
							</div>
						</div>
						<div class="text-end">
							<span class="badge bg-{{ $log->status=='late'?'warning':($log->status=='ontime'?'success':'secondary') }}-transparent">{{ $log->status }}</span>
							<div><small class="text-muted">{{ $log->scanned_at->format('h:i A') }}</small></div>
						</div>
					</li>
					@empty
					<li class="list-group-item text-center text-muted py-4">No attendance recorded yet. Employees check in from their portal to start.</li>
					@endforelse
				</ul>
			</div>
		</div>
	</div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/plugins/apexchart/apexcharts.min.js') }}"></script>
<script>
	var trendData = @json($trend);
	var options = {
		chart: { type: 'area', height: 300, toolbar: { show: false } },
		series: [{ name: 'Present', data: trendData.map(d => d.count) }],
		xaxis: { categories: trendData.map(d => d.label) },
		colors: ['#2563eb'],
		dataLabels: { enabled: false },
		stroke: { curve: 'smooth', width: 2 },
		fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
		grid: { borderColor: '#eef2f7' }
	};
	if (document.querySelector('#attendance-trend')) {
		new ApexCharts(document.querySelector('#attendance-trend'), options).render();
	}
</script>
@endpush
