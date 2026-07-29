@extends('layouts.app')
@section('title','Edit Employee')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Edit Employee</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
      <li class="breadcrumb-item active">Edit Employee</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap">
    <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Back</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<form action="{{ route('employees.update', $employee) }}" method="POST">
  @csrf
  @method('PUT')
  <div class="card">
    <div class="card-header"><h5 class="mb-0">Employee Information</h5></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Employee Code</label>
          <input type="text" name="employee_code" class="form-control" value="{{ old('employee_code', $employee->employee_code) }}">
          <small class="text-muted">Leave blank to auto-generate.</small>
        </div>
        <div class="col-md-4">
          <label class="form-label">First Name <span class="text-danger">*</span></label>
          <input type="text" name="first_name" class="form-control" value="{{ old('first_name', $employee->first_name) }}" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Last Name</label>
          <input type="text" name="last_name" class="form-control" value="{{ old('last_name', $employee->last_name) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="{{ old('email', $employee->email) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" value="{{ old('phone', $employee->phone) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Gender</label>
          <select name="gender" class="form-select">
            <option value="">Select Gender</option>
            <option value="male" @selected(old('gender', $employee->gender) === 'male')>Male</option>
            <option value="female" @selected(old('gender', $employee->gender) === 'female')>Female</option>
            <option value="other" @selected(old('gender', $employee->gender) === 'other')>Other</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Date of Birth</label>
          <input type="date" name="date_of_birth" class="form-control" value="{{ old('date_of_birth', $employee->date_of_birth?->format('Y-m-d')) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Hire Date</label>
          <input type="date" name="hire_date" class="form-control" value="{{ old('hire_date', $employee->hire_date?->format('Y-m-d')) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Office</label>
          <select name="office_id" class="form-select">
            <option value="">Select Office</option>
            @foreach($offices as $office)
              <option value="{{ $office->id }}" @selected(old('office_id', $employee->office_id) == $office->id)>{{ $office->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Department</label>
          <select name="department_id" class="form-select">
            <option value="">Select Department</option>
            @foreach($departments as $dept)
              <option value="{{ $dept->id }}" @selected(old('department_id', $employee->department_id) == $dept->id)>{{ $dept->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Designation</label>
          <select name="designation_id" class="form-select">
            <option value="">Select Designation</option>
            @foreach($designations as $designation)
              <option value="{{ $designation->id }}" @selected(old('designation_id', $employee->designation_id) == $designation->id)>{{ $designation->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Reports To</label>
          <select name="manager_id" class="form-select">
            <option value="">No manager</option>
            @foreach($managers as $manager)
              <option value="{{ $manager->id }}" @selected(old('manager_id', $employee->manager_id) == $manager->id)>{{ $manager->full_name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active" @selected(old('status', $employee->status) === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $employee->status) === 'inactive')>Inactive</option>
            <option value="terminated" @selected(old('status', $employee->status) === 'terminated')>Terminated</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Work Mode</label>
          <select name="work_mode" class="form-select">
            @foreach(\App\Models\Employee::WORK_MODES as $val => $label)
              <option value="{{ $val }}" @selected(old('work_mode', $employee->work_mode) === $val)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
    <div class="card-footer text-end">
      <a href="{{ route('employees.index') }}" class="btn btn-light me-2">Cancel</a>
      <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Update Employee</button>
    </div>
  </div>
</form>
@endsection
