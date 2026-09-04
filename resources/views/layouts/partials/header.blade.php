<!-- Header -->
<div class="header">
	<div class="main-header">

		<div class="header-left">
			<a href="{{ route('dashboard') }}" class="logo">
				<img src="{{ asset('assets/img/logo.png') }}" width="130" height="29" alt="Klutch Cleaning">
			</a>
			<a href="{{ route('dashboard') }}" class="dark-logo">
				<img src="{{ asset('assets/img/logo-white.png') }}" width="130" height="29" alt="Klutch Cleaning">
			</a>
		</div>

		<a id="mobile_btn" class="mobile_btn" href="#sidebar">
			<span class="bar-icon">
				<span></span><span></span><span></span>
			</span>
		</a>

		<div class="header-user">
			<div class="nav user-menu nav-list">

				<div class="me-auto d-flex align-items-center" id="header-search">
					<a id="toggle_btn" href="javascript:void(0);" class="btn btn-menubar me-2">
						<i class="ti ti-arrow-bar-to-left"></i>
					</a>
				</div>

				<div class="d-flex align-items-center">
					@include('layouts.partials.notification-bell')

					<!-- Theme toggle -->
					<div class="me-2">
						<a href="javascript:void(0);" id="dark-mode-toggle" class="btn btn-menubar activate">
							<i class="ti ti-moon"></i>
						</a>
						<a href="javascript:void(0);" id="light-mode-toggle" class="btn btn-menubar deactivate">
							<i class="ti ti-sun-high"></i>
						</a>
					</div>

					<!-- User dropdown -->
					<div class="dropdown profile-dropdown">
						<a href="javascript:void(0);" class="dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
							<span class="avatar avatar-sm online">
								<img src="{{ asset('assets/img/profiles/avatar-02.jpg') }}" alt="Img" class="img-fluid rounded-circle">
							</span>
							<span class="ms-2 d-none d-md-inline-block text-dark">{{ auth()->user()->name ?? 'Admin' }}</span>
						</a>
						<div class="dropdown-menu dropdown-menu-end shadow-none">
							<div class="card mb-0 border-0">
								<div class="card-header">
									<div class="d-flex align-items-center">
										<span class="avatar avatar-lg me-2 avatar-rounded">
											<img src="{{ asset('assets/img/profiles/avatar-02.jpg') }}" alt="img">
										</span>
										<div>
											<h5 class="mb-0">{{ auth()->user()->name ?? 'Admin' }}</h5>
											<p class="fs-12 fw-medium mb-0">{{ auth()->user()->email ?? '' }}</p>
										</div>
									</div>
								</div>
								<div class="card-body p-0">
									<a class="dropdown-item d-inline-flex align-items-center p-2" href="{{ route('profile.index') }}">
										<i class="ti ti-user-circle me-2"></i>My Profile
									</a>
									@can('manage-settings')
									<a class="dropdown-item d-inline-flex align-items-center p-2" href="{{ route('settings.index') }}">
										<i class="ti ti-settings me-2"></i>Settings
									</a>
									@endcan
								</div>
								<div class="card-footer p-0">
									<form method="POST" action="{{ route('logout') }}">
										@csrf
										<button type="submit" class="dropdown-item d-inline-flex align-items-center p-2 w-100 text-start">
											<i class="ti ti-logout me-2"></i>Logout
										</button>
									</form>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>

	</div>
</div>
<!-- /Header -->
