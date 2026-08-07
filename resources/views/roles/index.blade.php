@extends('layouts.app')
@section('title','Roles & Permissions')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Roles & Permissions</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Roles & Permissions</li>
    </ol></nav></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info d-flex align-items-center">
  <i class="ti ti-info-circle me-2"></i>
  {{-- This used to say login was admin-only and that hr/employee were
       structure waiting to be switched on. All four roles sign in today —
       LoginController admits admin, hr, employee and manager, and routes
       each to its own home — so the note was telling an administrator the
       opposite of what the system does. --}}
  <div>All four roles can sign in. Admin and HR reach this dashboard; employees and managers reach the self-service portal, with managers also seeing team approvals.</div>
</div>

@foreach($roles as $role)
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0">{{ ucfirst($role->name) }}</h5>
    <span class="badge bg-primary">{{ $role->users_count }} users</span>
  </div>
  <div class="card-body">
    <form action="{{ route('roles.update', $role) }}" method="POST">
      @csrf
      @method('PUT')
      <div class="row">
        @foreach($permissions as $permission)
        <div class="col-md-4 col-sm-6 mb-2">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="permissions[]"
              value="{{ $permission->name }}"
              id="perm-{{ $role->name }}-{{ $permission->name }}"
              {{ $role->permissions->contains('name', $permission->name) ? 'checked' : '' }}
              {{ $role->name === 'admin' ? 'disabled' : '' }}>
            <label class="form-check-label" for="perm-{{ $role->name }}-{{ $permission->name }}">
              {{ ucwords(str_replace('-', ' ', $permission->name)) }}
            </label>
          </div>
        </div>
        @endforeach
      </div>
      @if($role->name !== 'admin')
      <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-sm">Save Permissions</button>
      </div>
      @else
      <div class="mt-3">
        <span class="text-muted"><i class="ti ti-shield-lock me-1"></i>The admin role always has all permissions.</span>
      </div>
      @endif
    </form>
  </div>
</div>
@endforeach
@endsection
