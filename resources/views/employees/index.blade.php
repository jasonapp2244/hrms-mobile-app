@extends('layouts.app')
@section('title','Employees')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Employees</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Employees</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap">
    <a href="{{ route('employees.create') }}" class="btn btn-primary me-2 mb-1"><i class="ti ti-plus me-1"></i>Add Employee</a>
    <a href="{{ route('employees.import') }}" class="btn btn-outline-secondary me-2 mb-1"><i class="ti ti-upload me-1"></i>Bulk Import</a>
    {{-- Carries the current filters, so the button next to a filtered list
         exports what is actually on screen. --}}
    <a href="{{ route('employees.export', request()->only('q','department_id','status')) }}"
       class="btn btn-outline-success me-2 mb-1"><i class="ti ti-download me-1"></i>Export</a>
    <a href="{{ route('employees.org-chart') }}" class="btn btn-outline-secondary mb-1"><i class="ti ti-hierarchy-2 me-1"></i>Org Chart</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="card mb-3">
  <div class="card-body">
    <form action="{{ route('employees.index') }}" method="GET" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control" placeholder="Search name, code, email" value="{{ request('q') }}">
      </div>
      <div class="col-md-3">
        <label class="form-label">Department</label>
        <select name="department_id" class="form-select">
          <option value="">All Departments</option>
          @foreach($departments as $dept)
            <option value="{{ $dept->id }}" @selected(request('department_id') == $dept->id)>{{ $dept->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <option value="active" @selected(request('status') === 'active')>Active</option>
          <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
          <option value="terminated" @selected(request('status') === 'terminated')>Terminated</option>
        </select>
      </div>
      <div class="col-md-2 d-flex">
        <button type="submit" class="btn btn-primary me-2"><i class="ti ti-filter me-1"></i>Filter</button>
        <a href="{{ route('employees.index') }}" class="btn btn-light">Clear</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Email</th>
            <th>Department</th>
            <th>Designation</th>
            <th>Office</th>
            <th>Work Mode</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($employees as $e)
            <tr>
              <td>{{ $e->employee_code }}</td>
              <td>
                <div class="d-flex align-items-center">
                  <span class="avatar avatar-sm bg-primary text-white rounded-circle me-2">{{ strtoupper(substr($e->first_name, 0, 1)) }}</span>
                  <a href="{{ route('employees.show', $e) }}">{{ $e->full_name }}</a>
                </div>
              </td>
              <td>{{ $e->email }}</td>
              <td>{{ $e->department->name ?? '—' }}</td>
              <td>{{ $e->designation->name ?? '—' }}</td>
              <td>{{ $e->office->name ?? '—' }}</td>
              <td>
                @php
                  $modeMeta = [
                    'office' => ['ti-building', 'info', 'Office'],
                    'wfh'    => ['ti-home', 'success', 'WFH'],
                    'hybrid' => ['ti-arrows-shuffle', 'warning', 'Hybrid'],
                  ][$e->work_mode] ?? ['ti-building', 'info', 'Office'];
                @endphp
                <span class="badge bg-{{ $modeMeta[1] }}-transparent text-{{ $modeMeta[1] }}">
                  <i class="ti {{ $modeMeta[0] }} me-1"></i>{{ $modeMeta[2] }}
                </span>
              </td>
              <td>
                @php
                  $statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'terminated' => 'danger'][$e->status] ?? 'secondary';
                @endphp
                <span class="badge bg-{{ $statusBadge }}">{{ ucfirst($e->status) }}</span>
              </td>
              <td class="text-end">
                <a href="{{ route('employees.show', $e) }}" class="btn btn-sm btn-icon btn-light me-1" title="View"><i class="ti ti-eye"></i></a>
                <a href="{{ route('employees.edit', $e) }}" class="btn btn-sm btn-icon btn-light me-1" title="Edit"><i class="ti ti-edit"></i></a>
                <form action="{{ route('employees.destroy', $e) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this employee?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-icon btn-light text-danger" title="Delete"><i class="ti ti-trash"></i></button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="text-center text-muted py-4">No employees found.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="mt-3">{{ $employees->links() }}</div>
  </div>
</div>
@endsection
