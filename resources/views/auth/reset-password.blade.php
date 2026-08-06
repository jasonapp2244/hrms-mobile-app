@extends('auth.layout')

@section('title', 'Reset Password')
@section('action', route('password.update'))

@section('card')
	<div class="text-center mb-4">
		<h2 class="mb-2">Set a new password</h2>
		<p class="mb-0 text-muted">Choose a password you don't use anywhere else.</p>
	</div>

	@if ($errors->any())
		<div class="alert alert-danger d-flex align-items-center py-2 mb-3" role="alert">
			<i class="ti ti-alert-circle me-2"></i>
			<span>{{ $errors->first() }}</span>
		</div>
	@endif

	{{-- The token proves the request came from the mailbox; the address tells
		 the broker which account to hash it against. Neither is a secret the
		 person holding this page does not already have. --}}
	<input type="hidden" name="token" value="{{ $token }}">

	<div class="mb-3">
		<label class="form-label">Email Address</label>
		<div class="input-group">
			{{-- Readonly rather than hidden: someone with two work addresses
				 should be able to see which one they are resetting. Editing it
				 would only ever invalidate the token. --}}
			<input type="email" name="email" value="{{ old('email', $email) }}" class="form-control border-end-0 bg-light" readonly>
			<span class="input-group-text border-start-0">
				<i class="ti ti-mail"></i>
			</span>
		</div>
	</div>

	<div class="mb-3">
		<label class="form-label">New Password</label>
		<div class="pass-group">
			<input type="password" name="password" class="pass-input form-control" placeholder="••••••••" required autofocus autocomplete="new-password">
			<span class="ti toggle-password ti-eye-off"></span>
		</div>
		<small class="text-muted">At least 8 characters.</small>
	</div>

	<div class="mb-3">
		<label class="form-label">Confirm New Password</label>
		<div class="pass-group">
			<input type="password" name="password_confirmation" class="pass-input form-control" placeholder="••••••••" required autocomplete="new-password">
			<span class="ti toggle-password ti-eye-off"></span>
		</div>
	</div>

	<div class="mb-3">
		<button type="submit" class="btn btn-primary w-100">Save new password</button>
	</div>

	<div class="alert alert-light border mb-0 py-2 px-3" style="font-size:12.5px">
		<i class="ti ti-info-circle me-1"></i>
		Saving this signs you out everywhere else, including the mobile app.
	</div>
@endsection
