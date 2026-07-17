@extends('layouts.app')
@section('title', $title)

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">{{ $title }}</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Reports</li>
				<li class="breadcrumb-item active">{{ $title }}</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<button onclick="window.print()" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-printer me-1"></i>Print</button>
		@can('export-reports')
		<a href="{{ route('reports.'.$type, array_merge(request()->only('from','to','office_id'), ['export'=>'pdf'])) }}" class="btn btn-danger me-2 mb-2"><i class="ti ti-file-type-pdf me-1"></i>PDF</a>
		<a href="{{ route('reports.'.$type, array_merge(request()->only('from','to','office_id'), ['export'=>'excel'])) }}" class="btn btn-success mb-2"><i class="ti ti-file-spreadsheet me-1"></i>Excel</a>
		@endcan
	</div>
</div>

<!-- Report switcher -->
<ul class="nav nav-pills mb-3">
	<li class="nav-item"><a class="nav-link {{ $type=='late' ? 'active' : '' }}" href="{{ route('reports.late', request()->only('from','to','office_id')) }}">Late Arrivals</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='outliers' ? 'active' : '' }}" href="{{ route('reports.outliers', request()->only('from','to','office_id')) }}">Outliers</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='department' ? 'active' : '' }}" href="{{ route('reports.department', request()->only('from','to','office_id')) }}">Department</a></li>
	<li class="nav-item"><a class="nav-link" href="{{ route('attendance.report', request()->only('from','to','office_id')) }}">Attendance Summary</a></li>
</ul>

<!-- Filters -->
<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-3 align-items-end">
			<div class="col-md-3">
				<label class="form-label">From</label>
				<input type="date" name="from" value="{{ $from }}" class="form-control">
			</div>
			<div class="col-md-3">
				<label class="form-label">To</label>
				<input type="date" name="to" value="{{ $to }}" class="form-control">
			</div>
			<div class="col-md-3">
				<label class="form-label">Office</label>
				<select name="office_id" class="form-select">
					<option value="">All Offices</option>
					@foreach($offices as $o)
						<option value="{{ $o->id }}" {{ (string)request('office_id')===(string)$o->id ? 'selected' : '' }}>{{ $o->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-3">
				<button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i>Generate</button>
			</div>
		</form>
	</div>
</div>

<p class="text-muted mb-3">{{ $subtitle }}</p>

<!-- Summary tiles -->
<div class="row">
	@foreach($tiles as $t)
	<div class="col-md-3 col-6 mb-3">
		<div class="card"><div class="card-body text-center">
			<h3 class="mb-0">{{ $t['value'] }}</h3>
			<p class="text-muted mb-0 fs-13">{{ $t['label'] }}</p>
		</div></div>
	</div>
	@endforeach
</div>

<!-- Table -->
<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>@foreach($headings as $h)<th>{{ $h }}</th>@endforeach</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
					<tr>
						@foreach($headings as $h)
							@php $val = $row[$h]; @endphp
							<td>
								@if($h === 'On-time %')
									<span class="badge bg-{{ (float)$val < 70 ? 'danger' : ((float)$val < 90 ? 'warning' : 'success') }}-transparent">{{ $val }}</span>
								@elseif($h === 'Late %' || $h === 'Late Count')
									<span class="badge bg-warning-transparent">{{ $val }}</span>
								@elseif($h === 'Flag')
									<span class="badge bg-danger-transparent">{{ $val }}</span>
								@else
									{{ $val }}
								@endif
							</td>
						@endforeach
					</tr>
					@empty
					<tr><td colspan="{{ count($headings) }}" class="text-center text-muted py-4">No data for the selected filters — everyone is on track. 🎉</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
