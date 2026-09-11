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
  {{-- This has been wrong twice. It first said login was admin-only and that
       hr/employee were structure waiting to be switched on. It was corrected
       to say managers reach the self-service portal with team approvals bolted
       on — which was true until Stage 12 promoted the manager to a role with
       an area of its own at /manager/*. Each role lands somewhere different
       now, so the note names where. --}}
  <div>All four roles sign in and each lands somewhere different: <strong>Admin</strong> and <strong>HR</strong> on this dashboard, <strong>Managers</strong> on their own team area, <strong>Employees</strong> on the self-service portal.</div>
</div>

@foreach($roles as $role)
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div>
      {{-- The same helper the Sign-in Account panel uses, rather than a second
           ucfirst that renders the HR role as "Hr" — which is what this page
           did, two feet from a screen that got it right. --}}
      <h5 class="mb-0">{{ \App\Http\Controllers\EmployeeAccountController::roleLabel($role->name) }}</h5>
      {{-- What a checkbox cannot say. "Approve Leave" reads the same on the HR
           card and the Manager card and means something different on each:
           company-wide on one, a manager's own direct reports on the other. --}}
      @php($scope = [
        'admin' => 'Everything, including company setup, roles and settings.',
        'hr' => 'People operations company-wide — records, attendance, leave and reports. Not company configuration, which stays with the admin.',
        'manager' => 'A team lead. Every permission here is scoped to their own direct reports, never company-wide — and it is held in addition to Employee, not instead of it.',
        'employee' => 'Self-service only: their own clock-ins, their own leave.',
      ][$role->name] ?? null)
      @if($scope)<small class="text-muted">{{ $scope }}</small>@endif
    </div>
    <span class="badge bg-primary align-self-start">{{ $role->users_count }} users</span>
  </div>
  <div class="card-body">
    @if($role->name === 'manager')
    {{-- The trap this page cannot otherwise show: manager is a role *and* a
         relationship, and both have to line up. Ticking the boxes grants the
         gate; employees.manager_id decides the scope. Somebody given the role
         with nobody reporting to them signs in to a working area that is
         permanently empty, and nothing anywhere says why. --}}
    <div class="alert alert-warning d-flex align-items-start py-2 px-3 mb-3">
      <i class="ti ti-alert-triangle me-2 mt-1"></i>
      <div class="small">
        These permissions only do anything once somebody actually reports to the manager.
        Set <strong>Reporting Manager</strong> on each team member's employee record —
        a manager with no direct reports sees an area that works and is empty.
      </div>
    </div>
    @endif
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
