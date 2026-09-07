@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', $title)

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">{{ $title }}</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('manager.dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Team Reports</li>
				<li class="breadcrumb-item active">{{ $reports[$type]['label'] }}</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		{{-- Print rather than PDF/Excel. Exporting is `export-reports`, which the
			 manager role does not hold, and the browser's own print covers the
			 "put my team's late list on the wall" case without widening it. --}}
		<button onclick="window.print()" class="btn btn-outline-secondary mb-2">
			<i class="ti ti-printer me-1"></i>Print
		</button>
	</div>
</div>

<ul class="nav nav-pills mb-3 gap-2">
	@foreach($reports as $key => $meta)
	<li class="nav-item">
		<a class="nav-link {{ $type === $key ? 'active' : 'bg-white' }}"
			href="{{ route('manager.reports.show', array_merge(['type' => $key], request()->only('from', 'to'))) }}">
			{{ $meta['label'] }}
		</a>
	</li>
	@endforeach
</ul>

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
				<button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i>Generate</button>
			</div>
			<div class="col-md-3 text-md-end">
				{{-- No office filter. The scope is already the manager's team, and
					 a second narrowing control that could only ever remove people
					 from a list of four would read as though it might add some. --}}
				<span class="text-muted fs-13">
					{{ $team_size }} {{ \Illuminate\Support\Str::plural('person', $team_size) }} in scope
				</span>
			</div>
		</form>
	</div>
</div>

@if($team_size === 0)
	<div class="card">
		<div class="card-body text-center py-5">
			<i class="ti ti-users-off fs-1 text-muted d-block mb-3"></i>
			<h5 class="mb-1">Nobody reports to you yet</h5>
			<p class="text-muted mb-0">There is nobody for this report to be about.</p>
		</div>
	</div>
@else
	{{-- The same partial every HR report renders. The uniform
		 title/subtitle/tiles/headings/rows shape is what makes that possible,
		 and it is why a manager's Late Arrivals is the same report HR reads,
		 over fewer people, rather than a second implementation of it. --}}
	@include('reports.partials.results')
@endif
@endsection
