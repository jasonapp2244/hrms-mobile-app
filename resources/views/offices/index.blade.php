@extends('layouts.app')
@section('title','Offices & Branches')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Offices & Branches</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Offices & Branches</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-plus me-1"></i>Add Office</button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
  <i class="ti ti-info-circle me-1"></i>Each office generates its own dynamic rotating QR code for attendance. Rotating the secret immediately invalidates previously displayed codes.
</div>

<div class="card">
  <div class="card-header">
    <h5 class="mb-0">Offices & Branches</h5>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Name</th>
            <th>Code</th>
            <th>City</th>
            <th>Work Hours</th>
            <th>Grace</th>
            <th>Employees</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($offices as $o)
          <tr>
            <td>{{ $o->name }}</td>
            <td>{{ $o->code ?? '—' }}</td>
            <td>{{ $o->city ?? '—' }}</td>
            <td>{{ \Illuminate\Support\Str::of($o->work_start_time)->substr(0,5) }} - {{ \Illuminate\Support\Str::of($o->work_end_time)->substr(0,5) }}</td>
            <td>{{ $o->late_grace_minutes }} min</td>
            <td><span class="badge bg-light text-dark">{{ $o->employees_count }}</span></td>
            <td>
              @if($o->is_active)
                <span class="badge bg-success">Active</span>
              @else
                <span class="badge bg-danger">Inactive</span>
              @endif
            </td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $o->id }}"><i class="ti ti-edit"></i></button>
              <form action="{{ route('offices.rotate', $o) }}" method="POST" class="d-inline" onsubmit="return confirm('Rotate secret? Existing QR codes will stop working.');">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-warning" title="Rotate QR secret"><i class="ti ti-refresh"></i></button>
              </form>
              <form action="{{ route('offices.destroy', $o) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this office?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="editModal{{ $o->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form action="{{ route('offices.update', $o) }}" method="POST">
                  @csrf
                  @method('PUT')
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Office</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label">Name <span class="text-danger">*</span></label>
                      <input type="text" name="name" class="form-control" value="{{ old('name', $o->name) }}" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Code</label>
                      <input type="text" name="code" class="form-control" value="{{ old('code', $o->code) }}">
                    </div>
                    <div class="mb-3">
                      <label class="form-label">City</label>
                      <input type="text" name="city" class="form-control" value="{{ old('city', $o->city) }}">
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Address</label>
                      <textarea name="address" class="form-control" rows="2">{{ old('address', $o->address) }}</textarea>
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Work Start Time <span class="text-danger">*</span></label>
                        <input type="time" name="work_start_time" class="form-control" value="{{ old('work_start_time', \Illuminate\Support\Str::of($o->work_start_time)->substr(0,5)) }}" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Work End Time <span class="text-danger">*</span></label>
                        <input type="time" name="work_end_time" class="form-control" value="{{ old('work_end_time', \Illuminate\Support\Str::of($o->work_end_time)->substr(0,5)) }}" required>
                      </div>
                    </div>
                    <div class="mb-3 mt-3">
                      <label class="form-label">Late Grace (minutes) <span class="text-danger">*</span></label>
                      <input type="number" name="late_grace_minutes" class="form-control" value="{{ old('late_grace_minutes', $o->late_grace_minutes) }}" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Status</label>
                      <select name="is_active" class="form-select">
                        <option value="1" {{ $o->is_active ? 'selected' : '' }}>Active</option>
                        <option value="0" {{ !$o->is_active ? 'selected' : '' }}>Inactive</option>
                      </select>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          @empty
          <tr>
            <td colspan="8" class="text-center">No offices found.</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $offices->links() }}
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('offices.store') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Add Office</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Code</label>
            <input type="text" name="code" class="form-control" value="{{ old('code') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" value="{{ old('city') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Work Start Time <span class="text-danger">*</span></label>
              <input type="time" name="work_start_time" class="form-control" value="{{ old('work_start_time', '09:00') }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Work End Time <span class="text-danger">*</span></label>
              <input type="time" name="work_end_time" class="form-control" value="{{ old('work_end_time', '17:00') }}" required>
            </div>
          </div>
          <div class="mb-3 mt-3">
            <label class="form-label">Late Grace (minutes) <span class="text-danger">*</span></label>
            <input type="number" name="late_grace_minutes" class="form-control" value="{{ old('late_grace_minutes', 15) }}" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Office</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
