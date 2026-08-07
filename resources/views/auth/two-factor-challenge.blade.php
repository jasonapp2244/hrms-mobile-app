@extends('auth.layout')

@section('title', 'Two-Factor Verification')
@section('action', route('two-factor.verify'))

@section('card')
	<div class="text-center mb-4">
		<h2 class="mb-2">Enter your code</h2>
		<p class="mb-0 text-muted">
			Open your authenticator app and type the six-digit code for
			{{ config('app.name') }}. Codes change every 30 seconds.
		</p>
	</div>

	@if ($errors->any())
		<div class="alert alert-danger py-2 mb-3">
			<ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
		</div>
	@endif

	<div class="mb-3">
		<label class="form-label">Authentication code</label>
		{{-- inputmode numeric brings up the digit pad on a phone, and
		     autocomplete="one-time-code" lets iOS offer the code straight from
		     the notification. Long enough to hold a recovery code too, which is
		     what somebody with a lost handset will be typing. --}}
		<input type="text" name="code" class="form-control form-control-lg text-center"
		       inputmode="numeric" autocomplete="one-time-code" maxlength="19"
		       placeholder="000000" autofocus required>
		<div class="form-text">Lost your device? Type one of your recovery codes here instead.</div>
	</div>

	<div class="mb-3">
		<button type="submit" class="btn btn-primary w-100">Verify and sign in</button>
	</div>

	<div class="text-center">
		<a href="{{ route('login') }}" class="link-primary">Sign in as somebody else</a>
	</div>
@endsection
