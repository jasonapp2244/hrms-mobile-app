@extends('layouts.app')
@section('title','Employee Details')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Employee Details</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
      <li class="breadcrumb-item active">Employee Details</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap">
    <a href="{{ route('checklists.employee', $employee) }}" class="btn btn-outline-secondary me-2"><i class="ti ti-checklist me-1"></i>Checklist</a>
    <a href="{{ route('employees.documents.index', $employee) }}" class="btn btn-outline-secondary me-2"><i class="ti ti-folder me-1"></i>Documents</a>
    <a href="{{ route('employees.edit', $employee) }}" class="btn btn-primary"><i class="ti ti-edit me-1"></i>Edit</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

@php
  $statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'terminated' => 'danger'][$employee->status] ?? 'secondary';
@endphp

<div class="row">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        @if($employee->photo_url)
          <img src="{{ $employee->photo_url }}" class="rounded-circle mb-3" width="72" height="72"
               style="object-fit:cover" alt="{{ $employee->full_name }}">
        @else
          <span class="avatar avatar-xl bg-primary text-white rounded-circle mb-3 mx-auto d-flex align-items-center justify-content-center" style="width:72px;height:72px;font-size:1.75rem;">{{ strtoupper(substr($employee->first_name, 0, 1)) }}</span>
        @endif
        <h4 class="mb-1">{{ $employee->full_name }}</h4>
        <p class="text-muted mb-2">{{ $employee->employee_code }}</p>
        <span class="badge bg-{{ $statusBadge }}">{{ ucfirst($employee->status) }}</span>
      </div>
      <ul class="list-group list-group-flush">
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Email</span><span>{{ $employee->email ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Phone</span><span>{{ $employee->phone ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Department</span><span>{{ $employee->department->name ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Designation</span><span>{{ $employee->designation->name ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Office</span><span>{{ $employee->office->name ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <span class="text-muted">Shift</span>
          <span>
            @if($employee->shift)
              <span class="d-inline-block me-1" style="width:9px;height:9px;border-radius:50%;background:{{ $employee->shift->color }}"></span>{{ $employee->shift->name }}
              @if($employee->hasShiftOverride())
                <span class="badge bg-info-transparent text-info ms-1" title="Set for this employee, not inherited from their department">Own shift</span>
              @else
                <span class="text-muted small ms-1">via department</span>
              @endif
            @else
              —
            @endif
          </span>
        </li>
        <li class="list-group-item d-flex justify-content-between">
          <span class="text-muted">Working Hours</span>
          <span>
            @if($employee->shift)
              {{ $employee->shift->timing }}
              @if($employee->shift->crossesMidnight())
                <span class="badge bg-dark" title="Ends the following morning">Overnight</span>
              @endif
              <span class="text-muted small">· {{ $employee->shift->working_hours }} paid · {{ $employee->shift->late_grace_minutes }} min grace</span>
            @else
              <span class="text-muted">Not set</span>
            @endif
          </span>
        </li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Hire Date</span><span>{{ $employee->hire_date?->format('m/d/Y') ?? '—' }}</span></li>
      </ul>
    </div>

    {{-- Only rendered when there is something to show. An empty "Emergency
         Contact" card reads as if the system lost it, rather than as if nobody
         has filled it in. --}}
    @if($employee->emergency_contact_name || $employee->emergency_contact_phone)
    <div class="card mt-3 border-warning">
      <div class="card-header"><h6 class="mb-0"><i class="ti ti-urgent me-1"></i>Emergency Contact</h6></div>
      <ul class="list-group list-group-flush">
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Name</span><span>{{ $employee->emergency_contact_name ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Phone</span><span>{{ $employee->emergency_contact_phone ?? '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Relationship</span><span>{{ $employee->emergency_contact_relation ?? '—' }}</span></li>
      </ul>
    </div>
    @endif

    @if($employee->personal_email || $employee->address || $employee->national_id || $employee->blood_group)
    <div class="card mt-3">
      <div class="card-header"><h6 class="mb-0">Personal Details</h6></div>
      <ul class="list-group list-group-flush">
        @if($employee->personal_email)<li class="list-group-item d-flex justify-content-between"><span class="text-muted">Personal Email</span><span>{{ $employee->personal_email }}</span></li>@endif
        @if($employee->address)<li class="list-group-item"><span class="text-muted d-block mb-1">Address</span>{{ $employee->address }}{{ $employee->city ? ', ' . $employee->city : '' }}{{ $employee->country ? ', ' . $employee->country : '' }}</li>@endif
        @if($employee->national_id)<li class="list-group-item d-flex justify-content-between"><span class="text-muted">National ID</span><span>{{ $employee->national_id }}</span></li>@endif
        @if($employee->blood_group)<li class="list-group-item d-flex justify-content-between"><span class="text-muted">Blood Group</span><span>{{ $employee->blood_group }}</span></li>@endif
      </ul>
    </div>
    @endif

    {{-- Sign-in account (the login), which is not the same thing as the
         employee record above. Creating an employee deliberately does not mint
         a login; this is where one is minted when the person actually needs to
         use the portal or the phone app. --}}
    @php
      $account     = $employee->user;
      $assignable  = \App\Http\Controllers\EmployeeAccountController::assignableBy(auth()->user());
      $currentRole = $account?->getRoleNames()->first();
      $locked      = $currentRole && ! in_array($currentRole, $assignable, true);
      $lastLogin   = $account
        ? \App\Models\ActivityLog::where('user_id', $account->id)
            ->where('event', \App\Models\ActivityLog::LOGIN)
            ->latest('created_at')->value('created_at')
        : null;
    @endphp
    <div class="card mt-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="ti ti-key me-1"></i>Sign-in Account</h6>
        @if($account)
          <span class="badge bg-{{ $account->is_active ? 'success' : 'secondary' }}">{{ $account->is_active ? 'Active' : 'Disabled' }}</span>
        @else
          <span class="badge bg-warning-transparent text-warning">None</span>
        @endif
      </div>
      <div class="card-body">

        {{-- Shown once, right after it is generated. It is not stored in
             readable form anywhere, so this is the only chance to copy it. --}}
        @if(session('generated_password'))
          <div class="alert alert-warning">
            <div class="fw-semibold mb-1"><i class="ti ti-alert-triangle me-1"></i>Temporary password — shown once</div>
            <code class="fs-6">{{ session('generated_password') }}</code>
            <div class="small mt-1 mb-0">Hand this to {{ $employee->first_name }} directly. It cannot be shown again; use Reset password if it is lost.</div>
          </div>
        @endif

        @if(! $account)
          <p class="text-muted small">
            {{ $employee->first_name }} has no login and cannot sign in to the portal or the mobile app.
          </p>
          <form method="POST" action="{{ route('employees.account.store', $employee) }}">
            @csrf
            <div class="mb-2">
              <label class="form-label small mb-1">Sign-in email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control form-control-sm @error('email') is-invalid @enderror"
                     value="{{ old('email', $employee->email) }}" required>
              @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select form-select-sm @error('role') is-invalid @enderror" required>
                @foreach($assignable as $role)
                  <option value="{{ $role }}" @selected(old('role', 'employee') === $role)>{{ \App\Http\Controllers\EmployeeAccountController::roleLabel($role) }}</option>
                @endforeach
              </select>
              @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
              @unless(auth()->user()->can('manage-roles'))
                <div class="form-text small">Only an administrator can grant the HR or admin roles.</div>
              @endunless
            </div>
            <div class="mb-2">
              <label class="form-label small mb-1">Password</label>
              <input type="password" name="password" class="form-control form-control-sm @error('password') is-invalid @enderror"
                     placeholder="Leave blank to generate one" autocomplete="new-password">
              @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
              <input type="password" name="password_confirmation" class="form-control form-control-sm"
                     placeholder="Confirm password" autocomplete="new-password">
            </div>
            <button class="btn btn-primary btn-sm w-100"><i class="ti ti-user-plus me-1"></i>Create sign-in account</button>
          </form>
        @else
          <ul class="list-group list-group-flush mb-3">
            <li class="list-group-item d-flex justify-content-between px-0"><span class="text-muted">Email</span><span>{{ $account->email }}</span></li>
            <li class="list-group-item d-flex justify-content-between px-0"><span class="text-muted">Role</span><span>{{ $currentRole ? \App\Http\Controllers\EmployeeAccountController::roleLabel($currentRole) : '—' }}</span></li>
            <li class="list-group-item d-flex justify-content-between px-0"><span class="text-muted">Last signed in</span><span>{{ $lastLogin?->diffForHumans() ?? 'Never' }}</span></li>
          </ul>

          @if($locked)
            <p class="text-muted small mb-2">
              This account holds the {{ $currentRole }} role. Only an administrator can change or reset it.
            </p>
          @else
            <form method="POST" action="{{ route('employees.account.role', $employee) }}" class="mb-2">
              @csrf
              <label class="form-label small mb-1">Change role</label>
              <div class="input-group input-group-sm">
                <select name="role" class="form-select">
                  @foreach($assignable as $role)
                    <option value="{{ $role }}" @selected($currentRole === $role)>{{ \App\Http\Controllers\EmployeeAccountController::roleLabel($role) }}</option>
                  @endforeach
                </select>
                <button class="btn btn-outline-secondary">Save</button>
              </div>
            </form>

            <form method="POST" action="{{ route('employees.account.password', $employee) }}" class="mb-2">
              @csrf
              <button class="btn btn-outline-warning btn-sm w-100"><i class="ti ti-refresh me-1"></i>Reset password</button>
            </form>

            <form method="POST" action="{{ route('employees.account.toggle', $employee) }}">
              @csrf
              <button class="btn btn-outline-{{ $account->is_active ? 'danger' : 'success' }} btn-sm w-100">
                <i class="ti ti-{{ $account->is_active ? 'lock' : 'lock-open' }} me-1"></i>{{ $account->is_active ? 'Disable sign-in' : 'Enable sign-in' }}
              </button>
            </form>
          @endif
        @endif
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Recent Attendance</h5></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Type</th>
                <th>Status</th>
                <th>Time</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              @forelse($employee->attendanceLogs as $log)
                <tr>
                  <td><span class="badge bg-{{ $log->type === 'in' ? 'success' : 'secondary' }}">{{ ucfirst($log->type) }}</span></td>
                  <td><span class="badge bg-info">{{ ucfirst($log->status) }}</span></td>
                  <td>{{ $log->scanned_at?->format('h:i A') ?? '—' }}</td>
                  <td>{{ $log->work_date?->format('m/d/Y') ?? '—' }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="4" class="text-center text-muted py-4">No attendance records found.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
