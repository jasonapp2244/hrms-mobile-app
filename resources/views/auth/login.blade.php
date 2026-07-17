<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
	<meta name="robots" content="noindex, nofollow">
	<title>Login | {{ config('app.name') }}</title>

	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('assets/img/favicon.png') }}">
	<link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/icons/feather/feather.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/fontawesome/css/fontawesome.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/fontawesome/css/all.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>

<body class="bg-white">

	<!-- Main Wrapper -->
	<div class="main-wrapper">
		<div class="container-fuild">
			<div class="w-100 overflow-hidden position-relative flex-wrap d-block vh-100">
				<div class="row">

					<!-- Left: brand / hero panel -->
					<div class="col-lg-5">
						<div class="login-background position-relative d-lg-flex align-items-center justify-content-center d-none flex-wrap vh-100">
							<div class="bg-overlay-img">
								<img src="{{ asset('assets/img/bg/bg-01.png') }}" class="bg-1" alt="">
								<img src="{{ asset('assets/img/bg/bg-02.png') }}" class="bg-2" alt="">
								<img src="{{ asset('assets/img/bg/bg-03.png') }}" class="bg-3" alt="">
							</div>
							<div class="authentication-card w-100">
								<div class="authen-overlay-item border w-100">
									<h1 class="text-white fs-40 fw-bold">Attendance &amp; workforce <br> management, unified in <br> one secure platform.</h1>
									<div class="my-4 mx-auto authen-overlay-img">
										<img src="{{ asset('assets/img/bg/authentication-bg-01.png') }}" alt="">
									</div>
									<div>
										<p class="text-white fs-20 fw-semibold text-center">Secure QR check-ins, real-time dashboards, and <br> instant reports &mdash; for smarter people decisions.</p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Right: sign-in form -->
					<div class="col-lg-7 col-md-12 col-sm-12">
						<div class="row justify-content-center align-items-center vh-100 overflow-auto flex-wrap">
							<div class="col-md-8 col-lg-7 mx-auto vh-100">
								<form method="POST" action="{{ route('login') }}" class="vh-100">
									@csrf
									<div class="vh-100 d-flex flex-column justify-content-between p-4 pb-0">

										<div class="mx-auto mb-4 text-center">
											<img src="{{ asset('assets/img/logo.svg') }}" class="img-fluid" alt="{{ config('app.name') }}" style="max-height:42px">
										</div>

										<div class="">
											<div class="text-center mb-4">
												<h2 class="mb-2">Sign In</h2>
												<p class="mb-0 text-muted">Please enter your details to sign in</p>
											</div>

											@if ($errors->any())
												<div class="alert alert-danger d-flex align-items-center py-2 mb-3" role="alert">
													<i class="ti ti-alert-circle me-2"></i>
													<span>{{ $errors->first() }}</span>
												</div>
											@endif

											<div class="mb-3">
												<label class="form-label">Email Address</label>
												<div class="input-group">
													<input type="email" name="email" value="{{ old('email') }}" class="form-control border-end-0" placeholder="you@company.com" required autofocus>
													<span class="input-group-text border-start-0">
														<i class="ti ti-mail"></i>
													</span>
												</div>
											</div>

											<div class="mb-3">
												<label class="form-label">Password</label>
												<div class="pass-group">
													<input type="password" name="password" id="password" class="pass-input form-control" placeholder="••••••••" required>
													<span class="ti toggle-password ti-eye-off" id="toggle-password"></span>
												</div>
											</div>

											<div class="d-flex align-items-center justify-content-between mb-3">
												<div class="form-check form-check-md mb-0">
													<input class="form-check-input" id="remember_me" name="remember" type="checkbox">
													<label for="remember_me" class="form-check-label mt-0">Remember Me</label>
												</div>
											</div>

											<div class="mb-3">
												<button type="submit" class="btn btn-primary w-100">Sign In</button>
											</div>

											<div class="alert alert-light border mt-4 mb-0 py-2 px-3" style="font-size:12.5px">
												<div class="fw-semibold text-dark mb-1"><i class="ti ti-info-circle me-1"></i>Demo credentials</div>
												<div class="text-muted"><b>Admin:</b> admin@hrms.test / password</div>
												<div class="text-muted"><b>HR:</b> hr@hrms.test / password</div>
												<div class="text-muted"><b>Employee:</b> james.smith@acme.test / password</div>
											</div>
										</div>

										<div class="mt-5 pb-4 text-center">
											<p class="mb-0 text-gray-9">Copyright &copy; {{ date('Y') }} — {{ config('app.name') }}</p>
										</div>
									</div>
								</form>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>
	</div>
	<!-- /Main Wrapper -->

	<script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
	<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
	<script>
		(function () {
			var toggle = document.getElementById('toggle-password');
			var pw = document.getElementById('password');
			if (toggle && pw) {
				toggle.addEventListener('click', function () {
					if (pw.type === 'password') {
						pw.type = 'text';
						toggle.classList.remove('ti-eye-off');
						toggle.classList.add('ti-eye');
					} else {
						pw.type = 'password';
						toggle.classList.remove('ti-eye');
						toggle.classList.add('ti-eye-off');
					}
				});
			}
		})();
	</script>
</body>
</html>
