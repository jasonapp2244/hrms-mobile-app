{{--
	The chrome shared by every signed-out page: sign in, forgot password, and
	choose a new one.

	Extracted when the reset flow arrived and made three copies of it. The DOM is
	kept exactly as the login page had it — the three nested vh-100 elements look
	redundant but the SmartHR stylesheet leans on them, and flattening them
	collapses the layout.

	A child page supplies two things: `action` for the form, and `card` for what
	goes between the logo and the copyright line.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
	{{-- Nothing signed out should be indexed, and a reset link least of all. --}}
	<meta name="robots" content="noindex, nofollow">
	<title>@yield('title') | {{ config('app.name') }}</title>

	<link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon.svg') }}">
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
										<p class="text-white fs-20 fw-semibold text-center">One-tap check-ins, real-time dashboards, and <br> instant reports &mdash; for smarter people decisions.</p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Right: form panel -->
					<div class="col-lg-7 col-md-12 col-sm-12">
						<div class="row justify-content-center align-items-center vh-100 overflow-auto flex-wrap">
							<div class="col-md-8 col-lg-7 mx-auto vh-100">
								<form method="POST" action="@yield('action')" class="vh-100">
									@csrf
									<div class="vh-100 d-flex flex-column justify-content-between p-4 pb-0">

										<div class="mx-auto mb-4 text-center">
											<img src="{{ asset('assets/img/logo.svg') }}" class="img-fluid" alt="{{ config('app.name') }}" style="max-height:42px">
										</div>

										<div class="">
											@yield('card')
										</div>

										<div class="mt-5 pb-4 text-center">
											<p class="mb-0 text-gray-9">Copyright &copy; {{ date('Y') }} &mdash; {{ config('app.name') }}</p>
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
		// Show/hide for every password field on the page. Written against the
		// group rather than a single id because the reset form has two of them,
		// and an id can only ever wire up the first.
		(function () {
			document.querySelectorAll('.pass-group').forEach(function (group) {
				var toggle = group.querySelector('.toggle-password');
				var input = group.querySelector('.pass-input');
				if (!toggle || !input) return;

				toggle.addEventListener('click', function () {
					var hidden = input.type === 'password';
					input.type = hidden ? 'text' : 'password';
					toggle.classList.toggle('ti-eye-off', !hidden);
					toggle.classList.toggle('ti-eye', hidden);
				});
			});
		})();
	</script>
</body>
</html>
