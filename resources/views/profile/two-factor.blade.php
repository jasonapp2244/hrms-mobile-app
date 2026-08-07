@extends('layouts.app')
@section('title','Two-Factor Authentication')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Two-Factor Authentication</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('profile.index') }}">Profile</a></li>
      <li class="breadcrumb-item active">Two-Factor</li>
    </ol></nav></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

@if($codes)
<div class="card border-warning mb-3">
  <div class="card-header bg-warning-transparent"><h5 class="mb-0"><i class="ti ti-key me-1"></i>Recovery codes — shown once</h5></div>
  <div class="card-body">
    <p>
      Keep these somewhere safe and away from your phone. Each one signs you in
      exactly once if you lose your authenticator, and they are the only way back
      into this account without an administrator.
    </p>
    <pre class="bg-light p-3 rounded mb-0">@foreach($codes as $code){{ $code }}
@endforeach</pre>
  </div>
</div>
@endif

@if($user->hasTwoFactor())

<div class="card mb-3">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
      <h5 class="mb-1"><span class="badge bg-success me-2">On</span>Two-factor is protecting this account</h5>
      <p class="text-muted mb-0">
        Switched on {{ $user->two_factor_confirmed_at->format('M j, Y') }}.
        You will be asked for a code each time you sign in.
        {{ count($user->two_factor_recovery_codes ?? []) }} recovery code(s) left.
      </p>
    </div>
    <form method="POST" action="{{ route('two-factor.regenerate') }}">
      @csrf
      <button class="btn btn-outline-primary"><i class="ti ti-refresh me-1"></i>New recovery codes</button>
    </form>
  </div>
</div>

@if(! $user->mustUseTwoFactor())
<div class="card border-danger">
  <div class="card-header"><h5 class="mb-0">Turn it off</h5></div>
  <div class="card-body">
    <form method="POST" action="{{ route('two-factor.disable') }}" class="row g-3 align-items-end"
          onsubmit="return confirm('Turn off two-factor for your account?');">
      @csrf
      @method('DELETE')
      <div class="col-md-5">
        <label class="form-label">Confirm with your password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <div class="col-md-3">
        <button class="btn btn-danger">Turn off two-factor</button>
      </div>
    </form>
  </div>
</div>
@else
<div class="alert alert-info">
  <i class="ti ti-lock me-1"></i>
  Your company requires two-factor on administrator and HR accounts, so it cannot be turned off here.
</div>
@endif

@elseif($secret)

<div class="card">
  <div class="card-header"><h5 class="mb-0">Finish setting up</h5></div>
  <div class="card-body">
    <p>
      Add the key below to your authenticator app — Google Authenticator, Microsoft
      Authenticator, Authy, 1Password and the rest all take one. Choose
      <strong>"Enter a setup key"</strong>, paste it in, then type the code the app
      shows to confirm.
    </p>

    <div class="mb-3">
      <label class="form-label">Setup key</label>
      <input type="text" class="form-control font-monospace" readonly value="{{ $secret }}"
             onclick="this.select()">
      <div class="form-text">Account: {{ $user->email }} · Issuer: {{ config('app.name') }}</div>
    </div>

    <details class="mb-4">
      <summary class="text-muted">Full setup link</summary>
      <code class="d-block mt-2 small text-break">{{ $uri }}</code>
      <div class="form-text">
        Some password managers accept this link directly. There is no QR image yet —
        every authenticator supports typing the key in, which is what the field above is for.
      </div>
    </details>

    <form method="POST" action="{{ route('two-factor.confirm') }}" class="row g-3 align-items-end">
      @csrf
      <div class="col-md-4">
        <label class="form-label">Code from your app</label>
        <input type="text" name="code" class="form-control" inputmode="numeric"
               autocomplete="one-time-code" maxlength="6" placeholder="000000" required autofocus>
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary">Confirm and switch on</button>
      </div>
    </form>
  </div>
</div>

@else

<div class="card">
  <div class="card-body">
    <h5>Add a second step to signing in</h5>
    <p class="text-muted">
      With two-factor on, a password on its own is not enough to reach this account —
      a code from your phone is needed too. Recommended for anybody who can see or
      change other people's records.
    </p>
    @if($user->mustUseTwoFactor())
      <div class="alert alert-warning">
        <i class="ti ti-alert-triangle me-1"></i>
        Your company requires this on administrator and HR accounts. You will not be
        able to use the rest of the dashboard until it is set up.
      </div>
    @endif
    <form method="POST" action="{{ route('two-factor.enable') }}">
      @csrf
      <button class="btn btn-primary"><i class="ti ti-shield-lock me-1"></i>Set up two-factor</button>
    </form>
  </div>
</div>

@endif
@endsection
