@extends('layouts.app')
@section('title', 'Dashboard')

@php
	// Every panel is optional (A8.5), so each block guards on its own key and
	// the variables behind it only exist when the controller decided to gather
	// them. `has` keeps that check to one readable word.
	$has = fn (string $key) => in_array($key, $widgets, true);
@endphp

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Dashboard</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item active" aria-current="page">
					{{ auth()->user()->hasRole('admin') ? 'Administrator' : 'HR' }} Dashboard
				</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex my-xl-auto right-content align-items-center flex-wrap">
		<a href="{{ route('attendance.logs') }}" class="btn btn-primary me-2 mb-2"><i class="ti ti-list-check me-1"></i>Attendance Logs</a>
		<a href="{{ route('attendance.report') }}" class="btn btn-outline-secondary me-2 mb-2"><i class="ti ti-file-report me-1"></i>Reports</a>
		<button type="button" class="btn btn-outline-secondary mb-2" data-bs-toggle="modal" data-bs-target="#widgetModal">
			<i class="ti ti-layout-grid me-1"></i>Customise
		</button>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

@if(! count($widgets))
<div class="card">
	<div class="card-body text-center py-5">
		<h5>Your dashboard is empty</h5>
		<p class="text-muted">You have turned every panel off. Use <strong>Customise</strong> to bring some back.</p>
		<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#widgetModal">Choose panels</button>
	</div>
</div>
@endif

@if($has('tiles'))
<div class="row">
	@php
		$tiles = [
			['label'=>'Total Employees','value'=>$stats['employees'],'icon'=>'ti-users-group','bg'=>'primary'],
			['label'=>'Present Today','value'=>$stats['present'],'icon'=>'ti-user-check','bg'=>'success'],
			['label'=>'Late Today','value'=>$stats['late'],'icon'=>'ti-clock-exclamation','bg'=>'warning'],
			['label'=>'On Leave Today','value'=>$stats['on_leave'],'icon'=>'ti-beach','bg'=>'info'],
			['label'=>'Absent Today','value'=>$stats['absent'],'icon'=>'ti-user-x','bg'=>'danger'],
			['label'=>'Departments','value'=>$stats['departments'],'icon'=>'ti-building','bg'=>'secondary'],
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
@endif

@if($has('week_comparison'))
<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between flex-wrap">
		<h5 class="mb-0">This week vs last</h5>
		<span class="text-muted fs-13">{{ $comparison['this_week'] }} against {{ $comparison['last_week'] }}</span>
	</div>
	<div class="card-body">
		<div class="row">
			@foreach($comparison['metrics'] as $m)
			<div class="col-md-4 mb-3 mb-md-0">
				<div class="d-flex align-items-baseline gap-2">
					<h2 class="mb-0">{{ $m['now'] }}</h2>
					@if($m['delta'] === 0)
						<span class="badge bg-secondary-transparent">no change</span>
					@else
						<span class="badge bg-{{ $m['good'] ? 'success' : 'danger' }}-transparent">
							<i class="ti ti-arrow-{{ $m['delta'] > 0 ? 'up' : 'down' }}-right"></i>
							{{ $m['delta'] > 0 ? '+' : '' }}{{ $m['delta'] }}@if($m['percent'] !== null) ({{ $m['percent'] > 0 ? '+' : '' }}{{ $m['percent'] }}%)@endif
						</span>
					@endif
				</div>
				<p class="text-muted mb-0 fs-13">{{ $m['label'] }} · was {{ $m['was'] }}</p>
			</div>
			@endforeach
		</div>
		<p class="text-muted fs-12 mb-0 mt-3">
			Both windows run from Monday to the same weekday, so a Tuesday is compared with
			a Tuesday rather than with a finished week.
		</p>
	</div>
</div>
@endif

<div class="row">
	@if($has('attendance_trend'))
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
	@endif

	@if($has('who_is_in'))
	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0">Who is in right now</h5>
				<a href="{{ route('attendance.board') }}" class="btn btn-sm btn-light">Open board</a>
			</div>
			<div class="card-body">
				<div class="row text-center mb-3">
					<div class="col-3">
						<h3 class="mb-0 text-success">{{ $board['in'] }}</h3>
						<p class="text-muted mb-0 fs-12">On the clock</p>
					</div>
					<div class="col-3">
						<h3 class="mb-0">{{ $board['left'] }}</h3>
						<p class="text-muted mb-0 fs-12">Been and gone</p>
					</div>
					<div class="col-3">
						<h3 class="mb-0 text-info">{{ $board['on_leave'] }}</h3>
						<p class="text-muted mb-0 fs-12">On leave</p>
					</div>
					<div class="col-3">
						<h3 class="mb-0 {{ $board['not_in'] ? 'text-warning' : '' }}">{{ $board['not_in'] }}</h3>
						<p class="text-muted mb-0 fs-12">Unaccounted</p>
					</div>
				</div>

				@if($board['on_break'])
					<p class="text-muted fs-13 mb-2">{{ $board['on_break'] }} of them on a break.</p>
				@endif

				@if(count($board['missing']))
					<h6 class="fs-13 text-muted">Nobody knows where these are:</h6>
					<ul class="list-unstyled mb-0 fs-13">
						@foreach($board['missing'] as $person)
							<li><a href="{{ route('employees.show', $person) }}">{{ $person->full_name }}</a></li>
						@endforeach
						@if($board['not_in'] > count($board['missing']))
							<li class="text-muted">+{{ $board['not_in'] - count($board['missing']) }} more</li>
						@endif
					</ul>
				@else
					<p class="text-muted mb-0 fs-13">Everybody is accounted for. 🎉</p>
				@endif
			</div>
		</div>
	</div>
	@endif

	@if($has('pending_approvals'))
	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header"><h5 class="mb-0">Waiting on you</h5></div>
			<div class="card-body p-0">
				<ul class="list-group list-group-flush">
					<li class="list-group-item d-flex justify-content-between align-items-center">
						<a href="{{ route('leave.index', ['status' => 'pending']) }}">Leave requests</a>
						<span class="badge bg-{{ $approvals['leave'] ? 'warning text-dark' : 'light text-dark' }}">{{ $approvals['leave'] }}</span>
					</li>
					<li class="list-group-item d-flex justify-content-between align-items-center">
						<a href="{{ route('attendance.regularisations') }}">Attendance corrections</a>
						<span class="badge bg-{{ $approvals['regularisations'] ? 'warning text-dark' : 'light text-dark' }}">{{ $approvals['regularisations'] }}</span>
					</li>
					<li class="list-group-item d-flex justify-content-between align-items-center">
						<a href="{{ route('shift-swaps.index') }}">Shift swaps</a>
						<span class="badge bg-{{ $approvals['swaps'] ? 'warning text-dark' : 'light text-dark' }}">{{ $approvals['swaps'] }}</span>
					</li>
				</ul>
			</div>
		</div>
	</div>
	@endif

	@if($has('document_expiries'))
	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header"><h5 class="mb-0">Documents expiring</h5></div>
			<div class="card-body p-0">
				<ul class="list-group list-group-flush">
					@forelse($expiries as $doc)
					<li class="list-group-item d-flex justify-content-between align-items-center">
						<div>
							<a href="{{ route('employees.documents.index', $doc->employee) }}">{{ $doc->employee->full_name ?? 'Unknown' }}</a>
							<div class="text-muted fs-12">{{ $doc->title }}</div>
						</div>
						<span class="badge bg-{{ $doc->hasExpired() ? 'danger' : 'warning text-dark' }}">
							{{ $doc->expires_on->format('M j') }}
						</span>
					</li>
					@empty
					<li class="list-group-item text-center text-muted py-4">Nothing lapsing in the next month.</li>
					@endforelse
				</ul>
			</div>
		</div>
	</div>
	@endif

	@if($has('security'))
	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0">Security</h5>
				<a href="{{ route('activity.index') }}" class="btn btn-sm btn-light">Activity log</a>
			</div>
			<div class="card-body">
				<div class="row text-center mb-3">
					<div class="col-4">
						<h3 class="mb-0 {{ $security['failed_24h'] ? 'text-warning' : '' }}">{{ $security['failed_24h'] }}</h3>
						<p class="text-muted mb-0 fs-12">Failed sign-ins (24h)</p>
					</div>
					<div class="col-4">
						<h3 class="mb-0 {{ $security['lockouts_24h'] ? 'text-danger' : '' }}">{{ $security['lockouts_24h'] }}</h3>
						<p class="text-muted mb-0 fs-12">Lockouts (24h)</p>
					</div>
					<div class="col-4">
						<h3 class="mb-0">{{ $security['staff_with_2fa'] }}/{{ $security['staff_total'] }}</h3>
						<p class="text-muted mb-0 fs-12">Admin/HR with 2FA</p>
					</div>
				</div>

				<ul class="list-unstyled mb-0 fs-13">
					@forelse($security['recent'] as $entry)
					<li class="d-flex justify-content-between border-bottom py-1">
						<span>
							<span class="badge bg-{{ $entry->event_class }}">{{ $entry->event_label }}</span>
							{{ $entry->actor_label ?? '—' }}
						</span>
						<span class="text-muted">{{ $entry->created_at?->diffForHumans() }}</span>
					</li>
					@empty
					<li class="text-muted text-center py-2">Nothing to report.</li>
					@endforelse
				</ul>
			</div>
		</div>
	</div>
	@endif

	@if($has('recent_activity'))
	<div class="col-xl-5 mb-3">
		<div class="card h-100">
			<div class="card-header d-flex align-items-center justify-content-between">
				<h5 class="mb-0">Recent Punches</h5>
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
								<div class="mt-1" style="font-size:11.5px;line-height:1.4">
									@if($log->latitude && $log->longitude)
										<a href="https://www.google.com/maps?q={{ $log->latitude }},{{ $log->longitude }}" target="_blank" rel="noopener" class="text-info text-decoration-none me-2" title="{{ $log->latitude }}, {{ $log->longitude }}"><i class="ti ti-map-pin"></i> map</a>
									@else
										<span class="text-muted me-2"><i class="ti ti-map-pin-off"></i> no location</span>
									@endif
									@if($log->ip_address)
										<span class="text-muted"><i class="ti ti-world"></i> {{ $log->ip_address }}</span>
									@endif
								</div>
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
	@endif
</div>

<!-- Customise -->
<div class="modal fade" id="widgetModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<form action="{{ route('dashboard.widgets') }}" method="POST">
				@csrf
				<div class="modal-header">
					<h5 class="modal-title">Your dashboard</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">
						This is your screen, not your role's — nobody else is affected by what you
						choose here. Only panels you have permission to see are listed.
					</p>
					@foreach($available as $key => $widget)
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" name="widgets[]" value="{{ $key }}"
						       id="w_{{ $key }}" @checked(in_array($key, $widgets, true))>
						<label class="form-check-label" for="w_{{ $key }}">
							<strong>{{ $widget['label'] }}</strong>
							<div class="text-muted fs-13">{{ $widget['blurb'] }}</div>
						</label>
					</div>
					@endforeach
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Save</button>
				</div>
			</form>
		</div>
	</div>
</div>
@endsection

@push('scripts')
@if($has('attendance_trend'))
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
@endif
@endpush
