@extends('layouts.app')
@section('title', 'Dashboard')

@php
	// Every panel is optional (A8.5), so each block guards on its own key and
	// the variables behind it only exist when the controller decided to gather
	// them. `has` keeps that check to one readable word.
	$has = fn (string $key) => in_array($key, $widgets, true);

	// Initials in a tinted circle wherever a person has no photo — the same
	// fallback the employee screens use.
	$avatar = function ($person, string $size = '', string $tone = 'primary') {
		$class = trim('avatar ' . $size . ' avatar-rounded flex-shrink-0');
		if ($person?->photo_url) {
			return '<span class="' . $class . '"><img src="' . e($person->photo_url) . '" alt="" width="40" height="40" style="object-fit:cover"></span>';
		}
		$initials = strtoupper(substr($person->first_name ?? '?', 0, 1) . substr($person->last_name ?? '', 0, 1));
		return '<span class="' . $class . ' bg-' . $tone . '-transparent text-' . $tone . ' fw-semibold">' . e($initials) . '</span>';
	};

	// "+3" in green when up is good, red when it is not. Null when there is
	// nothing to compare with, so the badge is simply left off.
	$delta = function (?int $now, ?int $was, bool $upIsGood, string $suffix = '') {
		if ($now === null || $was === null) {
			return null;
		}
		$diff = $now - $was;
		if ($diff === 0) {
			return ['class' => 'bg-secondary', 'icon' => 'ti-minus', 'text' => '0' . $suffix];
		}
		$good = $upIsGood ? $diff > 0 : $diff < 0;
		return [
			'class' => $good ? 'bg-success' : 'bg-danger',
			'icon'  => $diff > 0 ? 'ti-arrow-up-right' : 'ti-arrow-down-right',
			'text'  => abs($diff) . $suffix,
		];
	};

	$punchLabel = ['in' => 'Clock in', 'out' => 'Clock out', 'break_start' => 'Break start', 'break_end' => 'Break end'];
	$punchIcon  = ['in' => 'ti-login-2', 'out' => 'ti-logout-2', 'break_start' => 'ti-coffee', 'break_end' => 'ti-player-play'];
	$punchTone  = ['in' => 'success', 'out' => 'secondary', 'break_start' => 'warning', 'break_end' => 'info'];

	$me = auth()->user();
	$firstName = \Illuminate\Support\Str::before(trim($me->name), ' ') ?: $me->name;
@endphp

@push('styles')
<style>
	.dash-kpi .avatar { color: #fff; }
	.dash-kpi .avatar i { position: relative; z-index: 1; }
	.dash-kpi .avatar .avatar-1 { background: rgba(255, 255, 255, .22) !important; }
	.dash-status .attendance-percent { color: rgba(255, 255, 255, .85); }
	.dash-status .img-1 { opacity: .9; pointer-events: none; }
	.dash-status .working-progress .progress-bar { border-radius: 4px; }
	.dash-list-item + .dash-list-item { margin-top: .5rem; }
	.dash-date-box { width: 46px; min-width: 46px; text-align: center; border-radius: 8px; line-height: 1.1; padding: 6px 0; }
	.dash-legend i { font-size: 10px; }
	.dash-scroll { max-height: 360px; overflow: auto; }
	.min-w-0 { min-width: 0; }
</style>
@endpush

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Dashboard</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item active" aria-current="page">{{ $me->hasRole('admin') ? 'Administrator' : 'HR' }} Dashboard</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex my-xl-auto right-content align-items-center flex-wrap">
		<a href="{{ route('attendance.report') }}" class="btn btn-white border me-2 mb-2"><i class="ti ti-file-report me-1"></i>Reports</a>
		<button type="button" class="btn btn-white border mb-2" data-bs-toggle="modal" data-bs-target="#widgetModal">
			<i class="ti ti-layout-grid me-1"></i>Customise
		</button>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

{{-- Welcome --}}
<div class="card border-0">
	<div class="card-body d-flex align-items-center justify-content-between flex-wrap pb-1">
		<div class="d-flex align-items-center mb-3">
			<span class="avatar avatar-xl avatar-rounded bg-primary text-white fs-24 fw-semibold flex-shrink-0">
				@if($me->employee?->photo_url)
					<img src="{{ $me->employee->photo_url }}" alt="" width="64" height="64" style="object-fit:cover">
				@else
					{{ strtoupper(substr($me->name, 0, 1)) }}
				@endif
			</span>
			<div class="ms-3">
				<h3 class="mb-1">Welcome back, {{ $firstName }}</h3>
				<p class="text-muted mb-1 fs-13">
					<i class="ti ti-calendar-event me-1"></i>{{ $welcome['now']->format('l, F j, Y') }}
					<span class="mx-1">·</span>
					<i class="ti ti-clock me-1"></i>{{ \App\Support\Clock::time($welcome['now']) }} company time
				</p>
				<p class="mb-0">
					You have
					<a href="{{ route('leave.index', ['status' => 'pending']) }}" class="text-primary text-decoration-underline fw-medium">{{ $welcome['leave'] }}</a>
					{{ \Illuminate\Support\Str::plural('leave request', $welcome['leave']) }} and
					<a href="{{ route('attendance.regularisations') }}" class="text-primary text-decoration-underline fw-medium">{{ $welcome['regularisations'] }}</a>
					{{ \Illuminate\Support\Str::plural('attendance correction', $welcome['regularisations']) }} waiting.
				</p>
			</div>
		</div>
		<div class="d-flex align-items-center flex-wrap mb-1">
			<a href="{{ route('attendance.board') }}" class="btn btn-white border me-2 mb-2"><i class="ti ti-users me-1"></i>Who is in</a>
			@if($qrLaunch)
				<button type="button" class="btn btn-white border me-2 mb-2" data-bs-toggle="modal" data-bs-target="#qrLaunchModal">
					<i class="ti ti-qrcode me-1"></i>Open QR screen
				</button>
			@endif
			<a href="{{ route('attendance.logs') }}" class="btn btn-primary mb-2"><i class="ti ti-list-check me-1"></i>Attendance Logs</a>
		</div>
	</div>
</div>

@if(! count($widgets))
<div class="card">
	<div class="card-body text-center py-5">
		<span class="avatar avatar-xl avatar-rounded bg-primary-transparent text-primary mb-3"><i class="ti ti-layout-dashboard fs-24"></i></span>
		<h5>Your dashboard is empty</h5>
		<p class="text-muted">You have turned every panel off. Use <strong>Customise</strong> to bring some back.</p>
		<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#widgetModal">Choose panels</button>
	</div>
</div>
@endif

{{-- Overview statistics --}}
@if($has('tiles'))
@php
	$before = $stats['before'];
	$versus = $stats['versus'] ? 'vs ' . $stats['versus'] : null;
	$tiles = [
		['label' => 'Total Employees', 'value' => $stats['employees'], 'icon' => 'ti-users-group', 'bg' => 'primary',
			'delta' => null, 'note' => 'active staff'],
		['label' => 'Present Today', 'value' => $stats['present'], 'icon' => 'ti-user-check', 'bg' => 'success',
			'delta' => $delta($stats['present'], $before['present'] ?? null, true)],
		['label' => 'Late Today', 'value' => $stats['late'], 'icon' => 'ti-clock-exclamation', 'bg' => 'warning',
			'delta' => $delta($stats['late'], $before['late'] ?? null, false)],
		['label' => 'On Leave Today', 'value' => $stats['on_leave'], 'icon' => 'ti-beach', 'bg' => 'info',
			'delta' => null, 'note' => 'approved leave'],
		['label' => 'Absent Today', 'value' => $stats['absent'], 'icon' => 'ti-user-x', 'bg' => 'danger',
			'delta' => $delta($stats['absent'], $before['absent'] ?? null, false)],
		['label' => 'Attendance Rate', 'value' => $stats['rate'] === null ? '—' : $stats['rate'] . '%', 'icon' => 'ti-chart-pie', 'bg' => 'purple',
			'delta' => $delta($stats['rate'], $before['rate'] ?? null, true, ' pts')],
		['label' => 'Departments', 'value' => $stats['departments'], 'icon' => 'ti-building', 'bg' => 'secondary',
			'delta' => null, 'note' => 'across the company'],
		['label' => 'Offices', 'value' => $stats['offices'], 'icon' => 'ti-building-community', 'bg' => 'indigo',
			'delta' => null, 'note' => 'sites'],
	];
@endphp
<div class="card">
	<div class="card-body">
		<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
			<h5 class="mb-0">Overview Statistics</h5>
			<span class="border rounded-pill px-3 py-1 fs-13 d-inline-flex align-items-center gap-1">
				<i class="ti ti-calendar"></i> Today
			</span>
		</div>
		<div class="row row-gap-3 dash-kpi">
			@foreach($tiles as $t)
			<div class="col-xxl-3 col-lg-4 col-sm-6 d-flex">
				<div class="card bg-light stat-card border shadow-none flex-fill">
					<div class="card-body">
						<div class="d-flex align-items-center justify-content-between gap-3">
							<div class="min-w-0">
								<p class="fs-14 fw-medium mb-2 text-truncate">{{ $t['label'] }}</p>
								<div class="d-flex align-items-center gap-2 flex-wrap">
									<h3 class="fs-20 mb-0">{{ $t['value'] }}</h3>
									@if($t['delta'] && $versus)
										<span class="d-inline-flex align-items-center gap-1 fs-12 text-muted">
											<span class="badge {{ $t['delta']['class'] }} d-inline-flex align-items-center gap-1 rounded fs-12">
												<i class="ti {{ $t['delta']['icon'] }}"></i>{{ $t['delta']['text'] }}
											</span>
											{{ $versus }}
										</span>
									@elseif(! empty($t['note']))
										<span class="fs-12 text-muted">{{ $t['note'] }}</span>
									@endif
								</div>
							</div>
							<div class="avatar avatar-lg bg-{{ $t['bg'] }} flex-shrink-0">
								<i class="ti {{ $t['icon'] }} fs-20"></i>
								<div class="avatar-1"></div>
							</div>
						</div>
					</div>
				</div>
			</div>
			@endforeach
		</div>
	</div>
</div>
@endif

<div class="row">

	{{-- Today's attendance --}}
	@if($has('attendance_donut'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Today's Attendance</h5>
				<span class="badge bg-light text-dark border mb-2">{{ $donut['total'] }} {{ \Illuminate\Support\Str::plural('employee', $donut['total']) }}</span>
			</div>
			<div class="card-body">
				@if($donut['total'])
					<div id="dash-donut" class="mb-3" style="min-height:230px"></div>
				@else
					<div class="text-center text-muted py-5">No active employees yet.</div>
				@endif
				@php
					$parts = [
						['label' => 'On time', 'value' => $donut['ontime'], 'tone' => 'success'],
						['label' => 'Late', 'value' => $donut['late'], 'tone' => 'warning'],
						['label' => 'On leave', 'value' => $donut['on_leave'], 'tone' => 'info'],
						['label' => 'Absent', 'value' => $donut['absent'], 'tone' => 'danger'],
					];
				@endphp
				<div class="dash-legend mb-3">
					@foreach($parts as $p)
					<div class="d-flex align-items-center justify-content-between mb-2">
						<p class="fs-13 mb-0 d-flex align-items-center"><i class="ti ti-circle-filled text-{{ $p['tone'] }} me-2"></i>{{ $p['label'] }}</p>
						<p class="fs-13 fw-medium text-dark mb-0">
							{{ $p['value'] }}
							@if($donut['total'])<span class="text-muted fw-normal">({{ round($p['value'] / $donut['total'] * 100) }}%)</span>@endif
						</p>
					</div>
					@endforeach
				</div>
				<div class="bg-light br-5 p-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
					<div class="d-flex align-items-center gap-2">
						<span class="mb-0 fs-13">Not in yet</span>
						@if($donut['missing'])
						<div class="avatar-list-stacked avatar-group-sm">
							@foreach($donut['absentees'] as $person)
								<a href="{{ route('employees.show', $person) }}" title="{{ $person->full_name }}">{!! $avatar($person, 'avatar-sm', 'danger') !!}</a>
							@endforeach
							@if($donut['missing'] > count($donut['absentees']))
								<span class="avatar avatar-sm avatar-rounded bg-danger text-white fs-12">+{{ $donut['missing'] - count($donut['absentees']) }}</span>
							@endif
						</div>
						@else
							<span class="badge bg-success-transparent">Everybody is accounted for</span>
						@endif
					</div>
					<a href="{{ route('attendance.board') }}" class="fs-13 link-primary text-decoration-underline">View board</a>
				</div>
			</div>
		</div>
	</div>
	@endif

	{{-- Who is in right now --}}
	@if($has('who_is_in'))
	@php
		$working = max(0, $board['in'] - $board['on_break']);
		$segments = [
			['label' => 'On the clock', 'value' => $working, 'tone' => 'success'],
			['label' => 'On a break', 'value' => $board['on_break'], 'tone' => 'warning'],
			['label' => 'Been and gone', 'value' => $board['left'], 'tone' => 'info'],
			['label' => 'On leave', 'value' => $board['on_leave'], 'tone' => 'purple'],
			['label' => 'Unaccounted', 'value' => $board['not_in'], 'tone' => 'danger'],
		];
		$segmentTotal = array_sum(array_column($segments, 'value'));
	@endphp
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card bg-dark position-relative flex-fill dash-status overflow-hidden">
			<div class="card-body position-relative z-1">
				<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
					<h5 class="mb-0 text-white">Who is in right now</h5>
					<a href="{{ route('attendance.board') }}" class="btn btn-sm btn-light">Open board</a>
				</div>
				<div class="mb-3">
					<p class="mb-1 text-white-50 fs-13">In the building or on the clock</p>
					<h3 class="mb-0 text-white">{{ $board['in'] }}</h3>
				</div>
				<div class="progress working-progress bg-soft-light py-1 px-1 mb-3" style="height:22px">
					@if($segmentTotal)
						@foreach($segments as $s)
							@if($s['value'])
							<div class="progress-bar bg-{{ $s['tone'] }} {{ $loop->last ? '' : 'me-1' }}" role="progressbar"
							     style="width: {{ round($s['value'] / $segmentTotal * 100, 1) }}%"
							     data-bs-toggle="tooltip" title="{{ $s['label'] }}: {{ $s['value'] }}"></div>
							@endif
						@endforeach
					@endif
				</div>
				@foreach($segments as $s)
				<div class="attendance-percent d-flex align-items-center justify-content-between gap-2 mb-2">
					<div class="fs-13"><i class="ti ti-circle-filled fs-10 text-{{ $s['tone'] }} me-1"></i>{{ $s['label'] }}</div>
					<p class="mb-0 text-white fw-medium">{{ $s['value'] }}</p>
				</div>
				@endforeach

				<div class="border-top border-secondary pt-3 mt-3">
					@if(count($board['missing']))
						<p class="text-white-50 fs-12 mb-2">Nobody knows where these are:</p>
						<div class="d-flex flex-wrap gap-1">
							@foreach($board['missing'] as $person)
								<a href="{{ route('employees.show', $person) }}" class="badge bg-light text-dark fw-normal">{{ $person->full_name }}</a>
							@endforeach
							@if($board['not_in'] > count($board['missing']))
								<span class="badge bg-danger fw-normal">+{{ $board['not_in'] - count($board['missing']) }} more</span>
							@endif
						</div>
					@else
						<p class="text-white mb-0 fs-13"><i class="ti ti-circle-check text-success me-1"></i>Everybody is accounted for.</p>
					@endif
				</div>
			</div>
			<img src="{{ asset('assets/img/bg/attendance-bg-2.png') }}" alt="" width="190" height="190"
			     class="img-fluid img-1 position-absolute top-0 end-0">
		</div>
	</div>
	@endif

	{{-- Late arrivals today --}}
	@if($has('late_today'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Late Arrivals Today <span class="badge badge-danger-transparent rounded-pill ms-1">{{ $lateToday['count'] }}</span></h5>
				<a href="{{ route('attendance.logs', ['date' => $welcome['now']->toDateString(), 'status' => 'late', 'type' => 'in']) }}" class="btn btn-light btn-sm mb-2">View all</a>
			</div>
			<div class="card-body">
				@forelse($lateToday['rows'] as $row)
				@php $person = $row['log']->employee; @endphp
				<div class="dash-list-item p-2 bg-light rounded d-flex align-items-center justify-content-between gap-2">
					<div class="d-flex align-items-center min-w-0">
						{!! $avatar($person, '', 'warning') !!}
						<div class="ms-2 min-w-0">
							<p class="fs-14 fw-medium text-truncate mb-0">
								@if($person)<a href="{{ route('employees.show', $person) }}">{{ $person->full_name }}</a>@else Unknown @endif
							</p>
							<p class="fs-12 text-muted mb-0 text-truncate">{{ $person?->department?->name ?? 'No department' }}</p>
						</div>
					</div>
					<div class="text-end flex-shrink-0">
						<p class="fs-13 mb-1 text-dark"><i class="ti ti-clock me-1"></i>{{ \App\Support\Clock::time($row['log']->scanned_at) }}</p>
						@if($row['minutes'])
							<span class="badge badge-danger-transparent rounded-pill">+{{ $row['minutes'] }} min</span>
						@endif
					</div>
				</div>
				@empty
				<div class="text-center py-5">
					<span class="avatar avatar-lg avatar-rounded bg-success-transparent text-success mb-2"><i class="ti ti-mood-happy fs-20"></i></span>
					<p class="text-muted mb-0">Nobody was late today.</p>
				</div>
				@endforelse
				@if($lateToday['count'] > count($lateToday['rows']))
					<p class="text-muted fs-12 mt-2 mb-0">+{{ $lateToday['count'] - count($lateToday['rows']) }} more late arrivals today.</p>
				@endif
			</div>
		</div>
	</div>
	@endif

	{{-- Attendance trend --}}
	@if($has('attendance_trend'))
	@php
		$monthTotal = $trendMonth->sum('count');
		$daysWithAnyone = $trendMonth->where('count', '>', 0)->count();
		$bestDay = $trendMonth->sortByDesc('count')->first();
	@endphp
	<div class="col-xxl-8 col-xl-12 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Attendance Trend</h5>
				<div class="btn-group btn-group-sm mb-2" role="group" aria-label="Trend range">
					<button type="button" class="btn btn-primary" data-trend-range="7">Last 7 days</button>
					<button type="button" class="btn btn-white border" data-trend-range="30">Last 30 days</button>
				</div>
			</div>
			<div class="card-body">
				<div class="row g-2 mb-2">
					<div class="col-sm-4">
						<div class="border rounded p-2 h-100">
							<p class="fs-12 text-muted mb-1">Average in per working day</p>
							<h5 class="mb-0">{{ $daysWithAnyone ? round($monthTotal / $daysWithAnyone, 1) : '—' }}</h5>
						</div>
					</div>
					<div class="col-sm-4">
						<div class="border rounded p-2 h-100">
							<p class="fs-12 text-muted mb-1">Late arrivals, 30 days</p>
							<h5 class="mb-0 text-warning">{{ $trendMonth->sum('late') }}</h5>
						</div>
					</div>
					<div class="col-sm-4">
						<div class="border rounded p-2 h-100">
							<p class="fs-12 text-muted mb-1">Busiest day</p>
							<h5 class="mb-0">@if($bestDay && $bestDay['count']){{ $bestDay['short'] }} <span class="fs-13 text-muted fw-normal">({{ $bestDay['count'] }} in)</span>@else — @endif</h5>
						</div>
					</div>
				</div>
				<div id="attendance-trend" style="min-height:290px"></div>
			</div>
		</div>
	</div>
	@endif

	{{-- Employees by department --}}
	@if($has('by_department'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Employees By Department</h5>
				<a href="{{ route('departments.index') }}" class="btn btn-light btn-sm mb-2">Departments</a>
			</div>
			<div class="card-body">
				@if($byDepartment['total'])
					<div id="dash-departments" style="min-height:260px"></div>
					<p class="fs-13 text-muted mb-0 mt-2">
						<i class="ti ti-users me-1"></i>{{ $byDepartment['total'] }} active across {{ $byDepartment['departments']->count() }} {{ \Illuminate\Support\Str::plural('department', $byDepartment['departments']->count()) }}@if($byDepartment['unassigned'])<span>, {{ $byDepartment['unassigned'] }} not yet in one</span>@endif.
					</p>
				@else
					<div class="text-center text-muted py-5">No active employees in any department yet.</div>
				@endif
			</div>
		</div>
	</div>
	@endif

	{{-- This week vs last --}}
	@if($has('week_comparison'))
	@php
		$weekIcon = ['present' => ['ti-user-check', 'success'], 'late' => ['ti-clock-exclamation', 'warning'], 'absent' => ['ti-user-x', 'danger']];
	@endphp
	<div class="col-12 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">This week vs last</h5>
				<span class="text-muted fs-13 mb-2">{{ $comparison['this_week'] }} against {{ $comparison['last_week'] }}</span>
			</div>
			<div class="card-body">
				<div class="row g-3">
					@foreach($comparison['metrics'] as $m)
					@php [$icon, $tone] = $weekIcon[$m['key']] ?? ['ti-chart-bar', 'primary']; @endphp
					<div class="col-md-4">
						<div class="border rounded p-3 d-flex align-items-center gap-3 h-100">
							<span class="avatar avatar-lg avatar-rounded bg-{{ $tone }}-transparent text-{{ $tone }} flex-shrink-0"><i class="ti {{ $icon }} fs-20"></i></span>
							<div>
								<p class="fs-13 text-muted mb-1">{{ $m['label'] }}</p>
								<div class="d-flex align-items-baseline gap-2 flex-wrap">
									<h3 class="mb-0">{{ $m['now'] }}</h3>
									@if($m['delta'] === 0)
										<span class="badge bg-secondary-transparent">no change</span>
									@else
										<span class="badge bg-{{ $m['good'] ? 'success' : 'danger' }}-transparent">
											<i class="ti ti-arrow-{{ $m['delta'] > 0 ? 'up' : 'down' }}-right"></i>
											{{ $m['delta'] > 0 ? '+' : '' }}{{ $m['delta'] }}@if($m['percent'] !== null) ({{ $m['percent'] > 0 ? '+' : '' }}{{ $m['percent'] }}%)@endif
										</span>
									@endif
								</div>
								<p class="text-muted mb-0 fs-12">was {{ $m['was'] }} last week</p>
							</div>
						</div>
					</div>
					@endforeach
				</div>
				<p class="text-muted fs-12 mb-0 mt-3">
					Both windows run from Monday to the same weekday, so a Tuesday is compared with
					a Tuesday rather than with a finished week.
				</p>
			</div>
		</div>
	</div>
	@endif

	{{-- Waiting on you --}}
	@if($has('pending_approvals'))
	@php
		$queues = [
			['label' => 'Leave', 'value' => $approvals['leave'], 'url' => route('leave.index', ['status' => 'pending']), 'icon' => 'ti-beach'],
			['label' => 'Corrections', 'value' => $approvals['regularisations'], 'url' => route('attendance.regularisations'), 'icon' => 'ti-edit-circle'],
			['label' => 'Shift swaps', 'value' => $approvals['swaps'], 'url' => route('shift-swaps.index'), 'icon' => 'ti-arrows-exchange'],
		];
	@endphp
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Waiting on you</h5>
				<a href="{{ route('leave.index', ['status' => 'pending']) }}" class="btn btn-light btn-sm mb-2">All requests</a>
			</div>
			<div class="card-body">
				<div class="row g-2 mb-3">
					@foreach($queues as $q)
					<div class="col-4">
						<a href="{{ $q['url'] }}" class="d-block border rounded p-2 text-center h-100 {{ $q['value'] ? 'border-warning' : '' }}">
							<i class="ti {{ $q['icon'] }} fs-18 {{ $q['value'] ? 'text-warning' : 'text-muted' }}"></i>
							<h5 class="mb-0 mt-1">{{ $q['value'] }}</h5>
							<p class="fs-12 text-muted mb-0 text-truncate">{{ $q['label'] }}</p>
						</a>
					</div>
					@endforeach
				</div>
				@forelse($approvals['requests'] as $request)
				<div class="dash-list-item border rounded p-2">
					<div class="d-flex align-items-center justify-content-between gap-2">
						<div class="d-flex align-items-center min-w-0">
							{!! $avatar($request->employee, 'avatar-sm') !!}
							<div class="ms-2 min-w-0">
								<p class="fs-14 fw-medium mb-0 text-truncate">{{ $request->employee?->full_name ?? 'Unknown' }}</p>
								<p class="fs-12 text-muted mb-0 text-truncate">
									{{ $request->leaveType?->name ?? 'Leave' }} ·
									{{ $request->start_date->format('M j') }}@if(! $request->end_date->isSameDay($request->start_date)) – {{ $request->end_date->format('M j') }}@endif
									· {{ rtrim(rtrim((string) $request->days, '0'), '.') }} {{ \Illuminate\Support\Str::plural('day', (float) $request->days == 1 ? 1 : 2) }}
								</p>
							</div>
						</div>
						<span class="badge badge-soft-warning bg-warning-transparent text-warning flex-shrink-0 fs-11">{{ $request->stage_label }}</span>
					</div>
				</div>
				@empty
				<div class="text-center py-4">
					<span class="avatar avatar-lg avatar-rounded bg-success-transparent text-success mb-2"><i class="ti ti-checks fs-20"></i></span>
					<p class="text-muted mb-0">No leave requests waiting.</p>
				</div>
				@endforelse
			</div>
		</div>
	</div>
	@endif

	{{-- Who's off soon --}}
	@if($has('upcoming'))
	@php $today = $welcome['now']->copy()->startOfDay(); @endphp
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Who's off soon</h5>
				<a href="{{ route('leave.calendar') }}" class="btn btn-light btn-sm mb-2">Leave calendar</a>
			</div>
			<div class="card-body">
				<h6 class="fs-13 text-muted mb-2">Approved leave, next 14 days</h6>
				@forelse($upcoming['leave'] as $request)
				@php
					$starts = \Carbon\Carbon::parse($request->start_date->toDateString(), $today->timezone);
					$away = $starts->lessThanOrEqualTo($today);
				@endphp
				<div class="dash-list-item d-flex align-items-center justify-content-between gap-2">
					<div class="d-flex align-items-center min-w-0">
						{!! $avatar($request->employee, 'avatar-sm', 'info') !!}
						<div class="ms-2 min-w-0">
							<p class="fs-14 fw-medium mb-0 text-truncate">{{ $request->employee?->full_name ?? 'Unknown' }}</p>
							<p class="fs-12 text-muted mb-0 text-truncate">
								{{ $request->leaveType?->name ?? 'Leave' }} ·
								{{ $request->start_date->format('M j') }}@if(! $request->end_date->isSameDay($request->start_date)) – {{ $request->end_date->format('M j') }}@endif
							</p>
						</div>
					</div>
					@if($away)
						<span class="badge bg-info-transparent text-info flex-shrink-0">Away now</span>
					@else
						<span class="badge bg-light text-dark border flex-shrink-0">in {{ (int) $today->diffInDays($starts) }} {{ \Illuminate\Support\Str::plural('day', (int) $today->diffInDays($starts)) }}</span>
					@endif
				</div>
				@empty
				<p class="text-muted fs-13 mb-0">Nobody has leave booked in the next two weeks.</p>
				@endforelse

				<h6 class="fs-13 text-muted mb-2 mt-4">Upcoming holidays</h6>
				@forelse($upcoming['holidays'] as $date => $name)
				@php $day = \Carbon\Carbon::parse($date); @endphp
				<div class="dash-list-item d-flex align-items-center gap-2">
					<div class="dash-date-box bg-primary-transparent text-primary">
						<div class="fs-11 text-uppercase">{{ $day->format('M') }}</div>
						<div class="fs-16 fw-bold">{{ $day->format('j') }}</div>
					</div>
					<div class="min-w-0">
						<p class="fs-14 fw-medium mb-0 text-truncate">{{ $name }}</p>
						<p class="fs-12 text-muted mb-0">{{ $day->format('l') }}</p>
					</div>
				</div>
				@empty
				<p class="text-muted fs-13 mb-0">No holidays in the next three months. <a href="{{ route('holidays.index') }}">Add holidays</a></p>
				@endforelse
			</div>
		</div>
	</div>
	@endif

	{{-- Birthdays --}}
	@if($has('birthdays'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Birthdays This Month</h5>
				<span class="badge bg-light text-dark border mb-2">{{ $welcome['now']->format('F') }}</span>
			</div>
			<div class="card-body">
				@forelse($birthdays as $person)
				@php $isToday = $person->date_of_birth->day === $welcome['now']->day; @endphp
				<div class="dash-list-item d-flex align-items-center justify-content-between gap-2 p-2 rounded {{ $isToday ? 'bg-primary-transparent' : '' }}">
					<div class="d-flex align-items-center min-w-0">
						{!! $avatar($person, 'avatar-sm', 'pink') !!}
						<div class="ms-2 min-w-0">
							<p class="fs-14 fw-medium mb-0 text-truncate"><a href="{{ route('employees.show', $person) }}">{{ $person->full_name }}</a></p>
							<p class="fs-12 text-muted mb-0 text-truncate">{{ $person->department?->name ?? 'No department' }}</p>
						</div>
					</div>
					@if($isToday)
						<span class="badge bg-primary flex-shrink-0"><i class="ti ti-cake me-1"></i>Today</span>
					@else
						<span class="badge bg-light text-dark border flex-shrink-0">{{ $person->date_of_birth->format('M j') }}</span>
					@endif
				</div>
				@empty
				<div class="text-center py-4">
					<span class="avatar avatar-lg avatar-rounded bg-pink-transparent text-pink mb-2"><i class="ti ti-cake fs-20"></i></span>
					<p class="text-muted mb-0">No birthdays this month.</p>
				</div>
				@endforelse
			</div>
		</div>
	</div>
	@endif

	{{-- Recent punches --}}
	@if($has('recent_activity'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Recent Punches</h5>
				<a href="{{ route('attendance.logs') }}" class="btn btn-light btn-sm mb-2">View all</a>
			</div>
			<div class="card-body dash-scroll">
				@forelse($recent as $log)
				@php $tone = $punchTone[$log->type] ?? 'secondary'; @endphp
				<div class="recent-item dash-list-item">
					<div class="d-flex align-items-center justify-content-between gap-2">
						<div class="d-flex align-items-center min-w-0">
							<span class="avatar avatar-rounded bg-{{ $tone }}-transparent text-{{ $tone }} flex-shrink-0">
								<i class="ti {{ $punchIcon[$log->type] ?? 'ti-fingerprint' }} fs-16"></i>
							</span>
							<div class="ms-2 min-w-0">
								<h6 class="fs-14 mb-0 text-truncate">{{ $log->employee->full_name ?? 'Unknown' }}</h6>
								<p class="fs-12 text-muted mb-0 text-truncate">
									{{ $punchLabel[$log->type] ?? ucfirst($log->type) }} · {{ $log->office->name ?? '' }}
									@if($log->looksTampered())
										<span class="badge bg-warning-transparent text-warning ms-1" title="{{ implode(', ', $log->tamperReasons()) }}"><i class="ti ti-alert-triangle"></i></span>
									@endif
								</p>
								{{-- Location and IP hidden everywhere, client request 2026-09-29. --}}
							</div>
						</div>
						<div class="text-end flex-shrink-0">
							<p class="fs-13 mb-1 text-dark">{{ \App\Support\Clock::time($log->scanned_at) }}</p>
							<span class="badge bg-{{ $log->status == 'late' ? 'warning' : ($log->status == 'ontime' ? 'success' : 'secondary') }}-transparent">{{ $log->status }}</span>
						</div>
					</div>
				</div>
				@empty
				<div class="text-center text-muted py-4">No attendance recorded yet. Employees check in from their portal to start.</div>
				@endforelse
			</div>
		</div>
	</div>
	@endif

	{{-- Documents expiring --}}
	@if($has('document_expiries'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Documents expiring</h5>
				<span class="badge bg-light text-dark border mb-2">next {{ \App\Models\EmployeeDocument::WARN_DAYS }} days</span>
			</div>
			<div class="card-body">
				@forelse($expiries as $doc)
				<div class="dash-list-item d-flex align-items-center justify-content-between gap-2 border rounded p-2">
					<div class="d-flex align-items-center min-w-0">
						<span class="avatar avatar-sm avatar-rounded bg-{{ $doc->hasExpired() ? 'danger' : 'warning' }}-transparent text-{{ $doc->hasExpired() ? 'danger' : 'warning' }} flex-shrink-0">
							<i class="ti ti-file-alert"></i>
						</span>
						<div class="ms-2 min-w-0">
							<p class="fs-14 fw-medium mb-0 text-truncate">
								@if($doc->employee)<a href="{{ route('employees.documents.index', $doc->employee) }}">{{ $doc->employee->full_name }}</a>@else Unknown @endif
							</p>
							<p class="fs-12 text-muted mb-0 text-truncate">{{ $doc->title }}</p>
						</div>
					</div>
					<span class="badge bg-{{ $doc->hasExpired() ? 'danger' : 'warning text-dark' }} flex-shrink-0">
						{{ $doc->hasExpired() ? 'Expired' : 'Expires' }} {{ $doc->expires_on->format('M j') }}
					</span>
				</div>
				@empty
				<div class="text-center py-4">
					<span class="avatar avatar-lg avatar-rounded bg-success-transparent text-success mb-2"><i class="ti ti-file-check fs-20"></i></span>
					<p class="text-muted mb-0">Nothing lapsing in the next month.</p>
				</div>
				@endforelse
			</div>
		</div>
	</div>
	@endif

	{{-- Security --}}
	@if($has('security'))
	<div class="col-xxl-4 col-xl-6 d-flex">
		<div class="card flex-fill">
			<div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap">
				<h5 class="mb-2">Security</h5>
				<a href="{{ route('activity.index') }}" class="btn btn-light btn-sm mb-2">Activity log</a>
			</div>
			<div class="card-body">
				<div class="row g-2 mb-3">
					<div class="col-4">
						<div class="border rounded p-2 text-center h-100">
							<h4 class="mb-0 {{ $security['failed_24h'] ? 'text-warning' : '' }}">{{ $security['failed_24h'] }}</h4>
							<p class="text-muted mb-0 fs-12">Failed sign-ins (24h)</p>
						</div>
					</div>
					<div class="col-4">
						<div class="border rounded p-2 text-center h-100">
							<h4 class="mb-0 {{ $security['lockouts_24h'] ? 'text-danger' : '' }}">{{ $security['lockouts_24h'] }}</h4>
							<p class="text-muted mb-0 fs-12">Lockouts (24h)</p>
						</div>
					</div>
					<div class="col-4">
						<div class="border rounded p-2 text-center h-100">
							<h4 class="mb-0">{{ $security['staff_with_2fa'] }}/{{ $security['staff_total'] }}</h4>
							<p class="text-muted mb-0 fs-12">Admin/HR with 2FA</p>
						</div>
					</div>
				</div>
				@forelse($security['recent'] as $entry)
				<div class="recent-item dash-list-item d-flex justify-content-between align-items-center gap-2">
					<span class="min-w-0 text-truncate fs-13">
						<span class="badge bg-{{ $entry->event_class }}">{{ $entry->event_label }}</span>
						{{ $entry->actor_label ?? '—' }}
					</span>
					<span class="text-muted fs-12 flex-shrink-0">{{ $entry->created_at?->diffForHumans() }}</span>
				</div>
				@empty
				<p class="text-muted text-center mb-0 py-2">Nothing to report.</p>
				@endforelse
			</div>
		</div>
	</div>
	@endif
</div>

@if($qrLaunch)
	@include('attendance.partials.qr-launch-modal')
@endif

<!-- Customise -->
<div class="modal fade" id="widgetModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
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
					<div class="row">
						@foreach($available as $key => $widget)
						<div class="col-md-6">
							<div class="form-check mb-3">
								<input class="form-check-input" type="checkbox" name="widgets[]" value="{{ $key }}"
								       id="w_{{ $key }}" @checked(in_array($key, $widgets, true))>
								<label class="form-check-label" for="w_{{ $key }}">
									<strong>{{ $widget['label'] }}</strong>
									<div class="text-muted fs-13">{{ $widget['blurb'] }}</div>
								</label>
							</div>
						</div>
						@endforeach
					</div>
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
@if($has('attendance_trend') || $has('attendance_donut') || $has('by_department'))
@php
	$chartData = [
		'donut'       => $has('attendance_donut') && $donut['total'] ? [
			'series' => [$donut['ontime'], $donut['late'], $donut['on_leave'], $donut['absent']],
			'rate'   => $donut['rate'],
		] : null,
		'trend'       => $has('attendance_trend') ? ['7' => $trend, '30' => $trendMonth] : null,
		'departments' => $has('by_department') && $byDepartment['total']
			? $byDepartment['departments']
				->map(fn ($d) => ['name' => $d->name, 'count' => $d->headcount])
				->when($byDepartment['unassigned'], fn ($c) => $c->push(['name' => 'No department', 'count' => $byDepartment['unassigned']]))
				->values()
			: null,
	];
@endphp
<script type="application/json" id="dashboard-data">@json($chartData)</script>
<script src="{{ asset('assets/plugins/apexchart/apexcharts.min.js') }}"></script>
<script src="{{ asset('assets/js/dashboard-charts.js') }}?v={{ filemtime(public_path('assets/js/dashboard-charts.js')) }}"></script>
@endif
@endpush
