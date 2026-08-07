<!-- Sidebar -->
<div class="sidebar" id="sidebar">
	<!-- Logo -->
	<div class="sidebar-logo">
		<a href="{{ route('dashboard') }}" class="logo logo-normal">
			<img src="{{ asset('assets/img/logo.svg') }}" alt="Logo">
		</a>
		<a href="{{ route('dashboard') }}" class="logo-small">
			<img src="{{ asset('assets/img/logo-small.svg') }}" alt="Logo">
		</a>
		<a href="{{ route('dashboard') }}" class="dark-logo">
			<img src="{{ asset('assets/img/logo-white.svg') }}" alt="Logo">
		</a>
	</div>
	<!-- /Logo -->

	<div class="sidebar-inner slimscroll">
		<div id="sidebar-menu" class="sidebar-menu">
			<ul>
				<li class="menu-title"><span>MAIN MENU</span></li>
				<li>
					<ul>
						@can('view-dashboard')
						<li class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
							<a href="{{ route('dashboard') }}">
								<i class="ti ti-smart-home"></i><span>Dashboard</span>
							</a>
						</li>
						@endcan

						@canany(['view-attendance', 'manage-attendance', 'view-reports'])
						<li class="submenu {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
							<a href="javascript:void(0);" class="{{ request()->routeIs('attendance.*') ? 'subdrop' : '' }}">
								<i class="ti ti-calendar-check"></i><span>Attendance</span><span class="menu-arrow"></span>
							</a>
							<ul style="{{ request()->routeIs('attendance.*') ? 'display:block;' : '' }}">
								@can('view-attendance')
								<li><a class="{{ request()->routeIs('attendance.index') ? 'active' : '' }}" href="{{ route('attendance.index') }}">Overview</a></li>
								<li><a class="{{ request()->routeIs('attendance.board') ? 'active' : '' }}" href="{{ route('attendance.board') }}">Who Is In</a></li>
								<li><a class="{{ request()->routeIs('attendance.logs') ? 'active' : '' }}" href="{{ route('attendance.logs') }}">Attendance Logs</a></li>
								@can('manage-attendance')
								<li><a class="{{ request()->routeIs('attendance.regularisations*') ? 'active' : '' }}" href="{{ route('attendance.regularisations') }}">Corrections</a></li>
								@endcan
								@endcan
							</ul>
						</li>
						@endcanany
					</ul>
				</li>

				@can('view-reports')
				<li class="menu-title"><span>REPORTS</span></li>
				<li>
					<ul>
						<li class="submenu {{ request()->routeIs('attendance.report') || request()->routeIs('reports.*') || request()->routeIs('report-subscriptions.*') ? 'active' : '' }}">
							<a href="javascript:void(0);" class="{{ request()->routeIs('attendance.report') || request()->routeIs('reports.*') || request()->routeIs('report-subscriptions.*') ? 'subdrop' : '' }}">
								<i class="ti ti-file-report"></i><span>HR Reports</span><span class="menu-arrow"></span>
							</a>
							<ul style="{{ request()->routeIs('attendance.report') || request()->routeIs('reports.*') || request()->routeIs('report-subscriptions.*') ? 'display:block;' : '' }}">
								<li><a class="{{ request()->routeIs('attendance.report') ? 'active' : '' }}" href="{{ route('attendance.report') }}">Attendance Summary</a></li>
								<li><a class="{{ request()->routeIs('reports.late') ? 'active' : '' }}" href="{{ route('reports.late') }}">Late Arrivals</a></li>
								<li><a class="{{ request()->routeIs('reports.outliers') ? 'active' : '' }}" href="{{ route('reports.outliers') }}">Outliers</a></li>
								<li><a class="{{ request()->routeIs('reports.overtime') ? 'active' : '' }}" href="{{ route('reports.overtime') }}">Overtime</a></li>
								<li><a class="{{ request()->routeIs('reports.payroll') ? 'active' : '' }}" href="{{ route('reports.payroll') }}">Payroll Hours</a></li>
								<li><a class="{{ request()->routeIs('reports.leave') ? 'active' : '' }}" href="{{ route('reports.leave') }}">Leave Report</a></li>
								@can('export-reports')
								<li><a class="{{ request()->routeIs('report-subscriptions.*') ? 'active' : '' }}" href="{{ route('report-subscriptions.index') }}">Scheduled Reports</a></li>
								@endcan
								<li><a class="{{ request()->routeIs('reports.department') ? 'active' : '' }}" href="{{ route('reports.department') }}">Department Report</a></li>
								<li><a class="{{ request()->routeIs('reports.custom') ? 'active' : '' }}" href="{{ route('reports.custom') }}">Report Builder</a></li>
							</ul>
						</li>
					</ul>
				</li>
				@endcan

				@canany(['manage-employees', 'manage-departments', 'manage-designations', 'manage-company', 'manage-offices'])
				<li class="menu-title"><span>HR MANAGEMENT</span></li>
				<li>
					<ul>
						@can('manage-employees')
						<li class="submenu {{ request()->routeIs('employees.*') ? 'active' : '' }}">
							<a href="javascript:void(0);" class="{{ request()->routeIs('employees.*') ? 'subdrop' : '' }}">
								<i class="ti ti-users-group"></i><span>Employees</span><span class="menu-arrow"></span>
							</a>
							<ul style="{{ request()->routeIs('employees.*') ? 'display:block;' : '' }}">
								<li><a class="{{ request()->routeIs('employees.index') ? 'active' : '' }}" href="{{ route('employees.index') }}">All Employees</a></li>
								<li><a class="{{ request()->routeIs('employees.org-chart') ? 'active' : '' }}" href="{{ route('employees.org-chart') }}">Org Chart</a></li>
								<li><a class="{{ request()->routeIs('employees.create') ? 'active' : '' }}" href="{{ route('employees.create') }}">Add Employee</a></li>
								@can('import-employees')
								<li><a class="{{ request()->routeIs('employees.import') ? 'active' : '' }}" href="{{ route('employees.import') }}">Bulk Import</a></li>
								@endcan
							</ul>
						</li>
						@endcan
						@can('manage-departments')
						<li class="{{ request()->routeIs('departments.*') ? 'active' : '' }}">
							<a href="{{ route('departments.index') }}"><i class="ti ti-building"></i><span>Departments</span></a>
						</li>
						@endcan
						@can('manage-designations')
						<li class="{{ request()->routeIs('designations.*') ? 'active' : '' }}">
							<a href="{{ route('designations.index') }}"><i class="ti ti-badge"></i><span>Designations</span></a>
						</li>
						@endcan
						@can('manage-shifts')
							<li class="submenu {{ request()->routeIs('shifts.*') || request()->routeIs('shift-swaps.*') ? 'active' : '' }}">
								<a href="javascript:void(0);" class="{{ request()->routeIs('shifts.*') || request()->routeIs('shift-swaps.*') ? 'subdrop' : '' }}">
									<i class="ti ti-clock-hour-4"></i><span>Shifts &amp; Schedule</span><span class="menu-arrow"></span>
								</a>
								<ul style="{{ request()->routeIs('shifts.*') || request()->routeIs('shift-swaps.*') ? 'display:block;' : '' }}">
									<li><a class="{{ request()->routeIs('shifts.index') ? 'active' : '' }}" href="{{ route('shifts.index') }}">Shifts</a></li>
									<li><a class="{{ request()->routeIs('shifts.roster') ? 'active' : '' }}" href="{{ route('shifts.roster') }}">Weekly Roster</a></li>
									<li><a class="{{ request()->routeIs('shift-swaps.*') ? 'active' : '' }}" href="{{ route('shift-swaps.index') }}">Shift Swaps</a></li>
								</ul>
							</li>
							@endcan
							@can('manage-leave')
							<li class="submenu {{ request()->routeIs('leave.*') || request()->routeIs('leave-types.*') || request()->routeIs('leave-balances.*') || request()->routeIs('holidays.*') ? 'active' : '' }}">
								<a href="javascript:void(0);" class="{{ request()->routeIs('leave.*') || request()->routeIs('leave-types.*') || request()->routeIs('leave-balances.*') || request()->routeIs('holidays.*') ? 'subdrop' : '' }}">
									<i class="ti ti-calendar-off"></i><span>Leave</span><span class="menu-arrow"></span>
								</a>
								<ul style="{{ request()->routeIs('leave.*') || request()->routeIs('leave-types.*') || request()->routeIs('leave-balances.*') || request()->routeIs('holidays.*') ? 'display:block;' : '' }}">
									<li><a class="{{ request()->routeIs('leave.index') ? 'active' : '' }}" href="{{ route('leave.index') }}">Leave Requests</a></li>
									<li><a class="{{ request()->routeIs('leave.calendar') ? 'active' : '' }}" href="{{ route('leave.calendar') }}">Leave Calendar</a></li>
									<li><a class="{{ request()->routeIs('leave-balances.*') ? 'active' : '' }}" href="{{ route('leave-balances.index') }}">Leave Balances</a></li>
									<li><a class="{{ request()->routeIs('leave-types.*') ? 'active' : '' }}" href="{{ route('leave-types.index') }}">Leave Types</a></li>
									<li><a class="{{ request()->routeIs('holidays.*') ? 'active' : '' }}" href="{{ route('holidays.index') }}">Holiday Calendar</a></li>
								</ul>
							</li>
							@endcan
							@canany(['manage-company', 'manage-offices'])
						<li class="submenu {{ request()->routeIs('company.*') || request()->routeIs('offices.*') ? 'active' : '' }}">
							<a href="javascript:void(0);" class="{{ request()->routeIs('company.*') || request()->routeIs('offices.*') ? 'subdrop' : '' }}">
								<i class="ti ti-building-community"></i><span>Company</span><span class="menu-arrow"></span>
							</a>
							<ul style="{{ request()->routeIs('company.*') || request()->routeIs('offices.*') ? 'display:block;' : '' }}">
								@can('manage-company')
								<li><a class="{{ request()->routeIs('company.index') ? 'active' : '' }}" href="{{ route('company.index') }}">Company Profile</a></li>
								@endcan
								@can('manage-offices')
								<li><a class="{{ request()->routeIs('offices.index') ? 'active' : '' }}" href="{{ route('offices.index') }}">Offices &amp; Branches</a></li>
								@endcan
							</ul>
						</li>
						@endcanany
					</ul>
				</li>
				@endcanany

				@canany(['manage-roles', 'manage-settings'])
				<li class="menu-title"><span>ADMINISTRATION</span></li>
				<li>
					<ul>
						@can('manage-roles')
						<li class="{{ request()->routeIs('roles.*') ? 'active' : '' }}">
							<a href="{{ route('roles.index') }}"><i class="ti ti-shield-lock"></i><span>Roles &amp; Permissions</span></a>
						</li>
						@endcan
						@can('manage-settings')
						<li class="{{ request()->routeIs('policies.*') ? 'active' : '' }}">
							<a href="{{ route('policies.edit') }}"><i class="ti ti-calendar-cog"></i><span>Working Week &amp; Policies</span></a>
						</li>
						<li class="{{ request()->routeIs('activity.*') ? 'active' : '' }}">
							<a href="{{ route('activity.index') }}"><i class="ti ti-list-check"></i><span>Activity Log</span></a>
						</li>
						<li class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">
							<a href="{{ route('settings.index') }}"><i class="ti ti-settings"></i><span>Settings</span></a>
						</li>
						@endcan
					</ul>
				</li>
				@endcanany

				<li class="menu-title"><span>FUTURE PHASE</span></li>
				<li>
					<ul>
						<li><a href="javascript:void(0);" class="text-muted"><i class="ti ti-robot"></i><span>AI Assistant</span><span class="badge badge-warning fs-10 ms-2">Soon</span></a></li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
</div>
<!-- /Sidebar -->
