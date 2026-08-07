{{--
  Reached by all four roles, so it cannot assume the admin chrome. Staff get
  the dashboard layout with its sidebar; employees and managers get the portal
  layout. Handing an employee the admin sidebar would fill their screen with
  links that every one of them 403s on.

  The home crumb follows the same rule via homeRoute() — it used to point at
  route('dashboard'), which is precisely the page a non-staff user is refused.
--}}
@php($staffLayout = auth()->user()->hasAnyRole(['admin', 'hr']))
@extends($staffLayout ? 'layouts.app' : 'layouts.employee')
@section('title','My Profile')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">My Profile</h2>
    {{-- Staff only. The portal draws its own nav pills, so a breadcrumb there
         is a second way to say the same thing — and its separator glyph comes
         from Font Awesome, which that layout does not load, so it rendered as
         an empty box. Not worth a whole icon font for one character. --}}
    @if($staffLayout)
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route(auth()->user()->homeRoute()) }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">My Profile</li>
    </ol></nav>
    @endif</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-user me-1"></i>Profile Information</h5>
      </div>
      <div class="card-body">
        <form action="{{ route('profile.update') }}" method="POST">
          @csrf
          @method('PUT')
          <div class="mb-3">
            <label class="form-label">Role</label><br>
            <span class="badge bg-info">{{ $user->roles->pluck('name')->implode(', ') }}</span>
          </div>
          <div class="mb-3">
            <label class="form-label" for="name">Name</label>
            <input type="text" class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="{{ old('email', $user->email) }}" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="phone">Phone</label>
            <input type="text" class="form-control" id="phone" name="phone" value="{{ old('phone', $user->phone) }}">
          </div>
          <button type="submit" class="btn btn-primary">Update Profile</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-lock me-1"></i>Change Password</h5>
      </div>
      <div class="card-body">
        <form action="{{ route('profile.password') }}" method="POST">
          @csrf
          @method('PUT')
          <div class="mb-3">
            <label class="form-label" for="current_password">Current Password</label>
            <input type="password" class="form-control" id="current_password" name="current_password" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">New Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password_confirmation">Confirm New Password</label>
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
          </div>
          <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-shield-lock me-1"></i>Two-Factor Authentication</h5>
      </div>
      <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          @if(auth()->user()->hasTwoFactor())
            <span class="badge bg-success me-1">On</span>
            <span class="text-muted">A code from your authenticator is required at sign-in.</span>
          @else
            <span class="badge bg-secondary me-1">Off</span>
            <span class="text-muted">Your password is currently the only thing protecting this account.</span>
          @endif
        </div>
        <a href="{{ route('two-factor.show') }}" class="btn btn-outline-primary">Manage</a>
      </div>
    </div>
  </div>
</div>
@endsection
