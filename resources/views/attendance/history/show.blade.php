@extends('layouts.app')
@section('title', $employee->full_name . ' — Attendance')

@section('content')
{{-- One person's attendance, day by day. Weekly and monthly are groupings of
	 the same rows rather than a different query: a summary that hides the days
	 it was computed from cannot be checked against the punches, which is the
	 reason somebody opens this screen at all. --}}
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">{{ $employee->full_name }}</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item"><a href="{{ route('attendance.history') }}">Attendance History</a></li>
				<li class="breadcrumb-item active">{{ $employee->employee_code }}</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('attendance.history') }}" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-arrow-left me-1"></i>Back</a>
		<button onclick="window.print()" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-printer me-1"></i>Print</button>
		@can('export-reports')
		<a href="{{ route('attendance.history.show', array_merge(['employee' => $employee->id], request()->query(), ['export' => 'pdf'])) }}" class="btn btn-danger me-2 mb-2"><i class="ti ti-file-type-pdf me-1"></i>PDF</a>
		<a href="{{ route('attendance.history.show', array_merge(['employee' => $employee->id], request()->query(), ['export' => 'excel'])) }}" class="btn btn-success mb-2"><i class="ti ti-file-spreadsheet me-1"></i>Excel</a>
		@endcan
	</div>
</div>

<div class="card mb-3">
	<div class="card-body d-flex flex-wrap gap-4">
		<div><small class="text-muted d-block">Staff number</small><span class="fw-semibold">{{ $employee->employee_code }}</span></div>
		<div><small class="text-muted d-block">Department</small><span class="fw-semibold">{{ $employee->department?->name ?? '—' }}</span></div>
		<div><small class="text-muted d-block">Designation</small><span class="fw-semibold">{{ $employee->designation?->name ?? '—' }}</span></div>
		<div><small class="text-muted d-block">Office</small><span class="fw-semibold">{{ $employee->office?->name ?? '—' }}</span></div>
	</div>
</div>

{{-- The period switcher. Each link drops from/to so the view picks its own
	 default window; the custom form below is how a range is named by hand. --}}
<ul class="nav nav-pills mb-3">
	@foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom Range'] as $key => $label)
	<li class="nav-item">
		<a class="nav-link {{ $view === $key ? 'active' : '' }}" href="{{ route('attendance.history.show', array_merge(['employee' => $employee->id, 'view' => $key], $key === 'custom' ? request()->only('from', 'to') : [])) }}">{{ $label }}</a>
	</li>
	@endforeach
</ul>

<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-3 align-items-end">
			<input type="hidden" name="view" value="{{ $view }}">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">From</label>
				<input type="date" name="from" value="{{ $from }}" class="form-control">
			</div>
			<div class="col-md-3 col-sm-6">
				<label class="form-label">To</label>
				<input type="date" name="to" value="{{ $to }}" class="form-control">
			</div>
			<div class="col-md-3 col-sm-6">
				<button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i>Apply</button>
			</div>
		</form>
	</div>
</div>

@php
	$tiles = [
		['label' => 'Present',       'value' => $totals['present_days'],                                  'icon' => 'ti-calendar-check', 'color' => 'success'],
		['label' => 'Absent',        'value' => $totals['absent_days'],                                   'icon' => 'ti-calendar-x',     'color' => 'danger'],
		['label' => 'Late',          'value' => $totals['late'],                                          'icon' => 'ti-alarm',          'color' => 'warning'],
		['label' => 'Early Out',     'value' => $totals['early_leave'],                                   'icon' => 'ti-door-exit',      'color' => 'warning'],
		['label' => 'Working Time',  'value' => \App\Support\Clock::duration($totals['worked_minutes']),  'icon' => 'ti-clock',          'color' => 'info'],
		['label' => 'Break Time',    'value' => \App\Support\Clock::duration($totals['break_minutes']),   'icon' => 'ti-coffee',         'color' => 'secondary'],
	];
@endphp

<div class="row">
	@foreach($tiles as $t)
	<div class="col-xl-2 col-md-4 col-6 mb-3">
		<div class="card h-100">
			<div class="card-body text-center">
				<span class="avatar avatar-lg bg-{{ $t['color'] }}-transparent text-{{ $t['color'] }} rounded-circle mb-2"><i class="ti {{ $t['icon'] }} fs-20"></i></span>
				<h4 class="mb-0">{{ $t['value'] }}</h4>
				<p class="text-muted mb-0 fs-13">{{ $t['label'] }}</p>
			</div>
		</div>
	</div>
	@endforeach
</div>

<div class="card">
	<div class="card-body">
		<p class="text-muted mb-3">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} to {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</p>
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Date</th>
						<th>Check-in</th>
						<th>Break Start</th>
						<th>Break End</th>
						<th class="text-end">Total Break</th>
						<th>Check-out</th>
						<th class="text-end">Total Working</th>
						<th>Status</th>
						<th>Late / Early</th>
						<th>Remarks</th>
					</tr>
				</thead>
				<tbody>
					@if($groups->isEmpty())
						@forelse($days as $day)
							@include('attendance.history.partials.day-row', ['day' => $day])
						@empty
							<tr><td colspan="10" class="text-center text-muted py-4">No days in this range.</td></tr>
						@endforelse
					@else
						@foreach($groups as $group)
							<tr class="table-light">
								<td colspan="4" class="fw-semibold">{{ $group['label'] }}</td>
								<td class="text-end fw-semibold">{{ \App\Support\Clock::duration($group['totals']['break_minutes']) }}</td>
								<td></td>
								<td class="text-end fw-semibold">{{ \App\Support\Clock::duration($group['totals']['worked_minutes']) }}</td>
								<td colspan="3" class="text-muted fs-13">{{ $group['totals']['present_days'] }} present · {{ $group['totals']['absent_days'] }} absent · {{ $group['totals']['late'] }} late</td>
							</tr>
							@foreach($group['days'] as $day)
								@include('attendance.history.partials.day-row', ['day' => $day])
							@endforeach
						@endforeach
					@endif
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection