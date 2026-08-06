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
		<a href="{{ route('password.request') }}" class="link-primary fw-medium">Forgot password?</a>
	</div>

	<div class="mb-3">
		<button type="submit" class="btn btn-primary w-100">Sign In</button>
	</div>

	{{-- The demo-credentials box that used to sit here printed
		 admin@hrms.test / password. It went with the demo seeder: on a
		 real install those accounts do not exist, so it advertised
		 logins that do not work — and on any install where they did
		 still exist, it published the exact pair `hrms:preflight`
		 fails a deploy for. Accounts now come from `hrms:install`. --}}
@endsection
