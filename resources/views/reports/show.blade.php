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

@include('reports.partials.nav')

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

@include('reports.partials.results')
@endsection
