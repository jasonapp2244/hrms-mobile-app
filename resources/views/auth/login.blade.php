@extends('auth.layout')

@section('title', 'Login')
@section('action', route('login'))

@section('card')
	<div class="text-center mb-4">
		<h2 class="mb-2">Sign In</h2>
		<p class="mb-0 text-muted">Please enter your details to sign in</p>
	</div>

	{{-- Where "your password has been reset, now sign in" lands. --}}
	@if (session('status'))
		<div class="alert alert-success d-flex align-items-center py-2 mb-3" role="alert">
			<i class="ti ti-circle-check me-2"></i>
			<span>{{ session('status') }}</span>
		</div>
	@endif

	@if ($errors->any())
		<div class="alert alert-danger d-flex align-items-center py-2 mb-3" role="alert">
			<i class="ti ti-alert-circle me-2"></i>
			<span>{{ $errors->first() }}</span>
		</div>
	@endif

	<div class="mb-3">
		<label class="form-label">Email Address</label>
		<div class="input-group">
			<input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control border-end-0" placeholder="you@company.com" required autofocus>
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
		<a href="{{ route('password.request') }}" class="link-primary fw-medium">Forgot password?</a>
	</div>

	<div class="mb-3">
		<button type="submit" class="btn btn-primary w-100">Sign In</button>
	</div>

	{{--
		One-click sign-in for demos and role testing.

		The box that used to sit here was hard-coded to admin@emp.test /
		password — it named accounts only the demo seeder creates, so on a real
		install it advertised logins that did not work, and it published the
		exact pair `emp:preflight` fails a deploy over.

		This one is empty unless DEMO_QUICK_LOGIN is on, is forced empty in
		production, and every row has been checked against the database before
		reaching here. See App\Support\QuickLogin.
	--}}
	@if ($quickLogins->isNotEmpty())
		<div class="border rounded p-3 mt-4 bg-light">
			<div class="d-flex align-items-center justify-content-between mb-2">
				<span class="fw-semibold text-gray-9">
					<i class="ti ti-bolt me-1"></i>Demo accounts
				</span>
				<span class="badge bg-warning-transparent text-warning">{{ strtoupper(app()->environment()) }} only</span>
			</div>

			<p class="text-muted fs-12 mb-3">
				Click a role to sign straight in. These are test accounts &mdash; this
				panel never appears on the live site.
			</p>

			<div class="d-grid gap-2">
				@foreach ($quickLogins as $account)
					<button type="button"
							class="btn btn-white border text-start d-flex align-items-center justify-content-between js-quick-login"
							data-email="{{ $account['email'] }}"
							data-password="{{ $account['password'] }}">
						<span class="d-flex flex-column">
							<span class="fw-semibold text-gray-9">{{ $account['roles'] }}</span>
							<span class="text-muted fs-12">{{ $account['email'] }}</span>
						</span>
						<span class="d-flex align-items-center">
							@if ($account['is_admin'])
								<span class="badge bg-danger-transparent text-danger me-2">Full access</span>
							@endif
							<i class="ti ti-arrow-right text-primary"></i>
						</span>
					</button>
				@endforeach
			</div>
		</div>

		<script>
			// Fill the real inputs and submit the real form, rather than posting
			// the credentials some other way — so a quick sign-in goes through
			// exactly the same validation, throttling and role checks as typing
			// them by hand would. Nothing here is a bypass.
			(function () {
				document.querySelectorAll('.js-quick-login').forEach(function (button) {
					button.addEventListener('click', function () {
						var form = button.closest('form');
						if (!form) return;

						form.querySelector('#email').value = button.dataset.email;
						form.querySelector('#password').value = button.dataset.password;

						// Guard against a double tap firing two POSTs.
						button.disabled = true;
						form.submit();
					});
				});
			})();
		</script>
	@endif
@endsection
