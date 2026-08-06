@extends('auth.layout')

@section('title', 'Forgot Password')
@section('action', route('password.email'))

@section('card')
	<div class="text-center mb-4">
		<h2 class="mb-2">Forgot Password?</h2>
		<p class="mb-0 text-muted">Enter the email you sign in with and we'll send you a link to set a new password.</p>
	</div>

	{{-- Always the same wording, whether or not the address has an account.
		 The controller explains why. --}}
	@if (session('status'))
		<div class="alert alert-success d-flex align-items-start py-2 mb-3" role="alert">
			<i class="ti ti-mail-check me-2 mt-1"></i>
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
		<button type="submit" class="btn btn-primary w-100">Send reset link</button>
	</div>

	<div class="text-center">
		<a href="{{ route('login') }}" class="link-primary fw-medium">
			<i class="ti ti-arrow-left me-1"></i>Back to sign in
		</a>
	</div>
@endsection
