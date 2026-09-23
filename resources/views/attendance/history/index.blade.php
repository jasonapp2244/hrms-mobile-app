@extends('layouts.app')
@section('title', 'Attendance History')

@section('content')
{{-- The register: every employee, summarised over the window. Clicking one
	 opens their day-by-day record. Nothing here is editable — corrections go
	 through the attendance log and the regularisation queue, which are the
	 only doors into an append-only table. --}}
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance History</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">History</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<button onclick="window.print()" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-printer me-1"></i>Print</button>
		@can('export-reports')
		<a href="{{ route('attendance.history', array_merge(request()->except('page'), ['export' => 'pdf'])) }}" class="btn btn-danger me-2 mb-2"><i class="ti ti-file-type-pdf me-1"></i>PDF</a>
		<a href="{{ route('attendance.history', array_merge(request()->except('page'), ['export' => 'excel'])) }}" class="btn btn-success mb-2"><i class="ti ti-file-spreadsheet me-1"></i>Excel</a>
		@endcan
	</div>
</div>

<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-3 align-items-end">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Search</label>
				<input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Name or staff number">
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">From</label>
				<input type="date" name="from" value="{{ $from }}" class="form-control">
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">To</label>
				<input type="date" name="to" value="{{ $to }}" class="form-control">
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Department</label>
				<select name="department_id" class="form-select">
					<option value="">All departments</option>
					@foreach($departments as $d)
						<option value="{{ $d->id }}" {{ (string) request('department_id') === (string) $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Office</label>
				<select name="office_id" class="form-select">
					<option value="">All offices</option>
					@foreach($offices as $o)
						<option value="{{ $o->id }}" {{ (string) request('office_id') === (string) $o->id ? 'selected' : '' }}>{{ $o->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-1 col-sm-6 d-flex">
				<button type="submit" class="btn btn-primary flex-grow-1"><i class="ti ti-filter"></i></button>
				<a href="{{ route('attendance.history') }}" class="btn btn-outline-secondary ms-1" title="Clear"><i class="ti ti-x"></i></a>
			</div>
		</form>
	</div>
</div>

@php
	$tiles = [
		['label' => 'Employees',    'value' => $totals['employees'],                                 'icon' => 'ti-users',          'color' => 'primary'],
		['label' => 'Present Days', 'value' => $totals['present_days'],                              'icon' => 'ti-calendar-check', 'color' => 'success'],
		['label' => 'Absent Days',  'value' => $totals['absent_days'],                               'icon' => 'ti-calendar-x',     'color' => 'danger'],
		['label' => 'Total Hours',  'value' => \App\Support\Clock::duration($totals['worked_minutes']), 'icon' => 'ti-clock',       'color' => 'info'],
	];

	// The sort link for a column heading. The current column flips direction,
	// any other starts ascending. except('page') because re-sorting a list and
	// staying on page four of the old order shows an unrelated slice.
	$sortLink = function (string $key, string $label) use ($sort, $dir) {
		$next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
		$icon = $sort === $key ? ($dir === 'asc' ? 'ti-caret-up-filled' : 'ti-caret-down-filled') : 'ti-selector';
		$href = route('attendance.history', array_merge(request()->except('page'), ['sort' => $key, 'dir' => $next]));

		return '<a class="text-body text-decoration-none" href="' . e($href) . '">'
			. e($label) . ' <i class="ti ' . $icon . ' fs-12 text-muted"></i></a>';
	};
@endphp

<div class="row">
	@foreach($tiles as $t)
	<div class="col-xl-3 col-sm-6 mb-3">
		<div class="card h-100">
			<div class="card-body d-flex align-items-center">
				<span class="avatar avatar-lg bg-{{ $t['color'] }}-transparent text-{{ $t['color'] }} rounded-circle me-3"><i class="ti {{ $t['icon'] }} fs-20"></i></span>
				<div>
					<h3 class="mb-0">{{ $t['value'] }}</h3>
					<p class="text-muted mb-0 fs-13">{{ $t['label'] }}</p>
				</div>
			</div>
		</div>
	</div>
	@endforeach
</div>

<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>{!! $sortLink('name', 'Employee') !!}</th>
						<th>{!! $sortLink('code', 'Staff No.') !!}</th>
						<th>{!! $sortLink('department', 'Department') !!}</th>
						<th>Designation</th>
						<th class="text-center">{!! $sortLink('present', 'Present') !!}</th>
						<th class="text-center">{!! $sortLink('absent', 'Absent') !!}</th>
						<th class="text-center">{!! $sortLink('late', 'Late') !!}</th>
						<th class="text-center">{!! $sortLink('early', 'Early Out') !!}</th>
						<th class="text-end">{!! $sortLink('worked', 'Working Hours') !!}</th>
						<th class="text-end">{!! $sortLink('break', 'Break Time') !!}</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
					<tr>
						<td>
							<a href="{{ route('attendance.history.show', array_merge(['employee' => $row['employee']->id], request()->only('from', 'to'))) }}" class="fw-semibold text-primary text-decoration-none">{{ $row['name'] }}</a>
						</td>
						<td class="text-muted">{{ $row['code'] }}</td>
						<td>{{ $row['department'] ?? '—' }}</td>
						<td>{{ $row['designation'] ?? '—' }}</td>
						<td class="text-center"><span class="badge bg-success-transparent text-success">{{ $row['present_days'] }}</span></td>
						<td class="text-center">
							@if($row['absent_days'] > 0)
								<span class="badge bg-danger-transparent text-danger">{{ $row['absent_days'] }}</span>
							@else
								<span class="text-muted">0</span>
							@endif
						</td>
						<td class="text-center">
							@if($row['late'] > 0)
								<span class="badge bg-warning-transparent text-warning">{{ $row['late'] }}</span>
							@else
								<span class="text-muted">0</span>
							@endif
						</td>
						<td class="text-center">
							@if($row['early_leave'] > 0)
								<span class="badge bg-warning-transparent text-warning">{{ $row['early_leave'] }}</span>
							@else
								<span class="text-muted">0</span>
							@endif
						</td>
						<td class="text-end fw-semibold">{{ \App\Support\Clock::duration($row['worked_minutes']) }}</td>
						<td class="text-end text-muted">{{ \App\Support\Clock::duration($row['break_minutes']) }}</td>
					</tr>
					@empty
					<tr><td colspan="10" class="text-center text-muted py-4">No employees match these filters.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>

		{{ $rows->links() }}
	</div>
</div>
@endsection