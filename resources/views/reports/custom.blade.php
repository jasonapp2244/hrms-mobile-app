@extends('layouts.app')
@section('title', $title)

@php
	// The export links have to carry the whole selection, not just the dates —
	// a PDF built from the default columns when the screen shows eleven of them
	// is the kind of bug people only notice after they have sent it on.
	$carried = request()->only('from','to','office_id','department_id','work_mode','columns');
@endphp

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Report Builder</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Reports</li>
				<li class="breadcrumb-item active">Build Your Own</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<button onclick="window.print()" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-printer me-1"></i>Print</button>
		@can('export-reports')
		<a href="{{ route('reports.custom', array_merge($carried, ['export'=>'pdf'])) }}" class="btn btn-danger me-2 mb-2"><i class="ti ti-file-type-pdf me-1"></i>PDF</a>
		<a href="{{ route('reports.custom', array_merge($carried, ['export'=>'excel'])) }}" class="btn btn-success mb-2"><i class="ti ti-file-spreadsheet me-1"></i>Excel</a>
		@endcan
	</div>
</div>

@include('reports.partials.nav')

<form method="GET">
	<div class="card mb-3">
		<div class="card-header d-flex justify-content-between align-items-center">
			<h5 class="mb-0">Filters</h5>
			<span class="text-muted fs-13">Pick a period, narrow the staff, then choose your columns.</span>
		</div>
		<div class="card-body">
			<div class="row g-3">
				<div class="col-md-3">
					<label class="form-label">From</label>
					<input type="date" name="from" value="{{ $from }}" class="form-control">
				</div>
				<div class="col-md-3">
					<label class="form-label">To</label>
					<input type="date" name="to" value="{{ $to }}" class="form-control">
				</div>
				<div class="col-md-2">
					<label class="form-label">Office</label>
					<select name="office_id" class="form-select">
						<option value="">All</option>
						@foreach($offices as $o)
							<option value="{{ $o->id }}" @selected((string)request('office_id')===(string)$o->id)>{{ $o->name }}</option>
						@endforeach
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label">Department</label>
					<select name="department_id" class="form-select">
						<option value="">All</option>
						@foreach($departments as $d)
							<option value="{{ $d->id }}" @selected((string)request('department_id')===(string)$d->id)>{{ $d->name }}</option>
						@endforeach
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label">Work Mode</label>
					<select name="work_mode" class="form-select">
						<option value="">All</option>
						@foreach(\App\Models\Employee::WORK_MODES as $key => $label)
							<option value="{{ $key }}" @selected(request('work_mode')===$key)>{{ $label }}</option>
						@endforeach
					</select>
				</div>
			</div>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header d-flex justify-content-between align-items-center">
			<h5 class="mb-0">Columns</h5>
			<div>
				<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.col-pick').forEach(c=>c.checked=true)">Select all</button>
				<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.col-pick').forEach(c=>c.checked=false)">Clear</button>
			</div>
		</div>
		<div class="card-body">
			<div class="row g-4">
				@foreach(\App\Services\ReportService::CUSTOM_COLUMNS as $group => $fields)
				<div class="col-md-4">
					<h6 class="text-uppercase text-muted fs-12 mb-2">{{ $group }}</h6>
					@foreach($fields as $key => $label)
					<div class="form-check">
						<input class="form-check-input col-pick" type="checkbox" name="columns[]" value="{{ $key }}"
						       id="col_{{ $key }}" @checked(in_array($key, $columns, true))>
						<label class="form-check-label" for="col_{{ $key }}">{{ $label }}</label>
					</div>
					@endforeach
				</div>
				@endforeach
			</div>
			<div class="mt-3 d-flex align-items-center gap-2">
				<button type="submit" class="btn btn-primary"><i class="ti ti-table me-1"></i>Generate Report</button>
				<span class="text-muted fs-13">Hours columns take a moment longer — they pair every punch in the period.</span>
			</div>
		</div>
	</div>
</form>

@include('reports.partials.results', ['emptyMessage' => 'Nobody matches these filters. Try widening the period, the office or the department.'])
@endsection
