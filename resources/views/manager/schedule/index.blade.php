@extends('layouts.app', ['sidebarPartial' => 'layouts.partials.manager-sidebar'])
@section('title', 'Team Schedule')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Team Schedule</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('manager.dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item active">
					{{ \Carbon\Carbon::parse($from)->format('j M') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('j M Y') }}
				</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('manager.schedule.index', ['from' => $prev]) }}" class="btn btn-outline-secondary me-2 mb-2">
			<i class="ti ti-chevron-left"></i>
		</a>
		<a href="{{ route('manager.schedule.index') }}" class="btn btn-outline-secondary me-2 mb-2">This fortnight</a>
		<a href="{{ route('manager.schedule.index', ['from' => $next]) }}" class="btn btn-outline-secondary mb-2">
			<i class="ti ti-chevron-right"></i>
		</a>
	</div>
</div>

{{-- Published days only, and read-only. Planning the roster is `manage-shifts`,
	 which this role does not hold — there is one planner and one publish step,
	 and a manager seeing drafts would tell somebody to come in on a day that is
	 still being moved around. --}}
<div class="card">
	<div class="card-body p-0">
		<div class="table-responsive">
			<table class="table table-bordered mb-0" style="min-width:900px">
				<thead>
					<tr>
						<th style="min-width:180px">Employee</th>
						@foreach($dates as $column)
						<th class="text-center">
							<div class="fs-13">{{ $column['label'] }}</div>
							<div class="fs-12 text-muted fw-normal">{{ $column['day'] }}</div>
						</th>
						@endforeach
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
					<tr>
						<td>
							<a href="{{ route('manager.team.show', $row['employee']) }}">{{ $row['employee']->full_name }}</a>
							<div class="fs-12 text-muted">{{ $row['employee']->employee_code }}</div>
						</td>
						@foreach($row['schedule'] as $day)
						<td class="text-center align-middle">
							@switch($day['status'])
								@case('working')
									@if($day['shift'])
										<div class="fs-13 fw-medium">{{ $day['shift']->name }}</div>
										<div class="fs-12 text-muted">{{ $day['shift']->start_time }}&ndash;{{ $day['shift']->end_time }}</div>
									@else
										<span class="text-muted fs-12">No shift set</span>
									@endif
									@break
								@case('leave')
									<span class="badge bg-primary">Leave</span>
									@break
								@case('holiday')
									<span class="badge bg-secondary" title="{{ $day['holiday'] }}">Holiday</span>
									@break
								@case('day_off')
									<span class="badge bg-secondary">Off</span>
									@break
								@default
									<span class="text-muted fs-12">&mdash;</span>
							@endswitch
						</td>
						@endforeach
					</tr>
					@empty
					<tr>
						<td colspan="{{ count($dates) + 1 }}" class="text-center text-muted py-4">
							Nobody reports to you yet. HR sets the line manager on an employee's record.
						</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
