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
        <span class="avatar avatar-xl bg-primary text-white rounded-circle mb-3 mx-auto d-flex align-items-center justify-content-center" style="width:72px;height:72px;font-size:1.75rem;">{{ strtoupper(substr($employee->first_name, 0, 1)) }}</span>
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
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Shift</span><span>{{ $employee->shift ? $employee->shift->name . ' (' . $employee->shift->timing . ')' : '—' }}</span></li>
        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Hire Date</span><span>{{ $employee->hire_date?->format('m/d/Y') ?? '—' }}</span></li>
      </ul>
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
