{{-- The manager's sidebar.

	 A different menu from the admin one rather than the same menu with things
	 hidden: every item here is a route inside the /manager group, so there is
	 no possibility of a link that renders for a role the middleware then
	 refuses. The admin sidebar is left untouched for the same reason — a
	 @can around an admin route would still be a route a manager cannot reach.

	 The last block is the manager's own working life. They are a member of
	 staff who clocks in like everybody else, and a supervisor's dashboard with
	 no way to start their own shift would send them hunting for the portal. --}}
<div class="sidebar" id="sidebar">
	<!-- Logo -->
	<div class="sidebar-logo">
		<a href="{{ route('manager.dashboard') }}" class="logo logo-normal">
			<img src="{{ asset('assets/img/logo.png') }}" width="130" height="29" alt="{{ config('app.name') }}">
		</a>
		<a href="{{ route('manager.dashboard') }}" class="logo-small">
			<img src="{{ asset('assets/img/logo-small.png') }}" width="30" height="30" alt="{{ config('app.name') }}">
		</a>
		<a href="{{ route('manager.dashboard') }}" class="dark-logo">
			<img src="{{ asset('assets/img/logo-white.png') }}" width="130" height="29" alt="{{ config('app.name') }}">
		</a>
	</div>
	<!-- /Logo -->

	<div class="sidebar-inner slimscroll">
		<div id="sidebar-menu" class="sidebar-menu">
			<ul>
				<li class="menu-title"><span>MY TEAM</span></li>
				<li>
					<ul>
						<li class="{{ request()->routeIs('manager.dashboard') ? 'active' : '' }}">
							<a href="{{ route('manager.dashboard') }}">
								<i class="ti ti-smart-home"></i><span>Dashboard</span>
							</a>
						</li>
						<li class="{{ request()->routeIs('manager.team.*') ? 'active' : '' }}">
							<a href="{{ route('manager.team.index') }}">
								<i class="ti ti-users-group"></i><span>My Team</span>
							</a>
						</li>
						<li class="submenu {{ request()->routeIs('manager.attendance.*') ? 'active' : '' }}">
							<a href="javascript:void(0);" class="{{ request()->routeIs('manager.attendance.*') ? 'subdrop' : '' }}">
								<i class="ti ti-calendar-check"></i><span>Attendance</span><span class="menu-arrow"></span>
							</a>
							<ul style="{{ request()->routeIs('manager.attendance.*') ? 'display:block;' : '' }}">
								<li><a class="{{ request()->routeIs('manager.attendance.index') ? 'active' : '' }}" href="{{ route('manager.attendance.index') }}">Today</a></li>
								<li><a class="{{ request()->routeIs('manager.attendance.logs') ? 'active' : '' }}" href="{{ route('manager.attendance.logs') }}">Punch Log</a></li>
							</ul>
						</li>
						<li class="{{ request()->routeIs('manager.schedule.*') ? 'active' : '' }}">
							<a href="{{ route('manager.schedule.index') }}">
								<i class="ti ti-calendar-time"></i><span>Schedule</span>
							</a>
						</li>
						@can('approve-leave')
						<li class="{{ request()->routeIs('manager.approvals.*') ? 'active' : '' }}">
							<a href="{{ route('manager.approvals.index') }}">
								<i class="ti ti-checklist"></i><span>Approvals</span>
							</a>
						</li>
						@endcan
					</ul>
				</li>

				<li class="menu-title"><span>REPORTS</span></li>
				<li>
					<ul>
						<li class="submenu {{ request()->routeIs('manager.reports.*') ? 'active' : '' }}">
							<a href="javascript:void(0);" class="{{ request()->routeIs('manager.reports.*') ? 'subdrop' : '' }}">
								<i class="ti ti-file-report"></i><span>Team Reports</span><span class="menu-arrow"></span>
							</a>
							<ul style="{{ request()->routeIs('manager.reports.*') ? 'display:block;' : '' }}">
								@foreach(\App\Http\Controllers\Manager\ReportController::REPORTS as $key => $meta)
								<li>
									<a class="{{ request()->routeIs('manager.reports.show') && request()->route('type') === $key ? 'active' : '' }}"
										href="{{ route('manager.reports.show', $key) }}">{{ $meta['label'] }}</a>
								</li>
								@endforeach
							</ul>
						</li>
					</ul>
				</li>

				<li class="menu-title"><span>MY WORKSPACE</span></li>
				<li>
					<ul>
						<li>
							<a href="{{ route('employee.dashboard') }}">
								<i class="ti ti-clock-play"></i><span>My Attendance</span>
							</a>
						</li>
						<li>
							<a href="{{ route('employee.leave.index') }}">
								<i class="ti ti-calendar-off"></i><span>My Leave</span>
							</a>
						</li>
						<li>
							<a href="{{ route('employee.swaps.index') }}">
								<i class="ti ti-arrows-exchange"></i><span>Shift Swaps</span>
							</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
</div>