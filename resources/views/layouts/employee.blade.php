<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>@yield('title', 'My Attendance') | {{ config('app.name') }}</title>
	<link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon.svg') }}">
	<link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/icons/feather/feather.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
	<style>
		body { background:#f5f6f8; }
		.emp-topbar { background:#fff; border-bottom:1px solid #e6e9ef; }
		.emp-topbar .logo { height:34px; }
		.emp-wrap { max-width:760px; margin:0 auto; padding:20px 16px 60px; }
		.emp-avatar { width:44px; height:44px; border-radius:50%; background:#fff2ec; color:#e8622e;
			display:inline-flex; align-items:center; justify-content:center; font-weight:700; }
	</style>
</head>
<body>
	<div class="emp-topbar">
		<div class="emp-wrap d-flex align-items-center justify-content-between py-2" style="padding-bottom:8px!important;padding-top:8px!important">
			<img src="{{ asset('assets/img/logo.svg') }}" class="logo" alt="{{ config('app.name') }}">
			<div class="d-flex align-items-center gap-2">
				<span class="d-none d-sm-inline text-muted small">{{ auth()->user()->name }}</span>
				<form method="POST" action="{{ route('logout') }}">
					@csrf
					<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-logout me-1"></i>Logout</button>
				</form>
			</div>
		</div>
	</div>

	<div class="emp-wrap">
		{{-- Portal nav. Two destinations only — this area is deliberately small. --}}
		<ul class="nav nav-pills mb-3 gap-2">
			<li class="nav-item">
				<a class="nav-link {{ request()->routeIs('employee.dashboard') ? 'active' : 'bg-white' }}"
					href="{{ route('employee.dashboard') }}"><i class="ti ti-calendar-check me-1"></i>Attendance</a>
			</li>
			<li class="nav-item">
				<a class="nav-link {{ request()->routeIs('employee.leave.*') ? 'active' : 'bg-white' }}"
					href="{{ route('employee.leave.index') }}"><i class="ti ti-calendar-off me-1"></i>My Leave</a>
			</li>
		</ul>

		@yield('content')
	</div>

	<script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
	<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
	@stack('scripts')
</body>
</html>
