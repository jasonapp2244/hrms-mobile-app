@extends('layouts.app')
@section('title','Shifts & Schedule')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Shifts &amp; Schedule</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Shifts &amp; Schedule</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap gap-2">
    <a href="{{ route('shifts.roster') }}" class="btn btn-outline-primary"><i class="ti ti-calendar-week me-1"></i>Weekly Roster</a>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-plus me-1"></i>Add Shift</button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
  <i class="ti ti-info-circle me-1"></i>Define your working shifts here, then assign each employee to a shift from their profile. Shift timing drives their expected start/end for scheduling.
</div>

<div class="card">
  <div class="card-header"><h5 class="mb-0">Shifts</h5></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Shift</th>
            <th>Code</th>
            <th>Timing</th>
            <th>Break</th>
            <th>Grace</th>
            <th>Departments</th>
            <th>Employees</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($shifts as $s)
          <tr>
            <td>
              <span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:{{ $s->color }}"></span>
              {{ $s->name }}
            </td>
            <td>{{ $s->code ?? '—' }}</td>
            <td>
              {{ $s->timing }}
              @if($s->crossesMidnight())
                <span class="badge bg-dark ms-1" title="This shift ends the following morning">Overnight</span>
              @endif
              <div class="text-muted small">{{ $s->working_hours }} paid</div>
            </td>
            <td>{{ $s->break_minutes }} min</td>
            <td>{{ $s->late_grace_minutes }} min</td>
            <td><span class="badge bg-light text-dark">{{ $s->departments_count }}</span></td>
            <td><span class="badge bg-light text-dark">{{ $s->employees_count }}</span></td>
            <td>
              @if($s->is_active)
                <span class="badge bg-success">Active</span>
              @else
                <span class="badge bg-danger">Inactive</span>
              @endif
            </td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $s->id }}"><i class="ti ti-edit"></i></button>
              <form action="{{ route('shifts.destroy', $s) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this shift? Departments on it will be unassigned.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="editModal{{ $s->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form action="{{ route('shifts.update', $s) }}" method="POST">
                  @csrf
                  @method('PUT')
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Shift</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label">Shift Name <span class="text-danger">*</span></label>
                      <input type="text" name="name" class="form-control" value="{{ old('name', $s->name) }}" required>
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" class="form-control" value="{{ old('code', $s->code) }}">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Color</label>
                        <input type="color" name="color" class="form-control form-control-color" value="{{ old('color', $s->color) }}">
                      </div>
                    </div>
                    <div class="row g-3 mt-0">
                      <div class="col-md-6">
                        <label class="form-label">Start Time <span class="text-danger">*</span></label>
                        <input type="time" name="start_time" class="form-control" value="{{ old('start_time', \Illuminate\Support\Str::of($s->start_time)->substr(0,5)) }}" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">End Time <span class="text-danger">*</span></label>
                        <input type="time" name="end_time" class="form-control" value="{{ old('end_time', \Illuminate\Support\Str::of($s->end_time)->substr(0,5)) }}" required>
                      </div>
                    </div>
                    <div class="row g-3 mt-0">
                      <div class="col-md-6">
                        <label class="form-label">Break (minutes) <span class="text-danger">*</span></label>
                        <input type="number" name="break_minutes" class="form-control" value="{{ old('break_minutes', $s->break_minutes) }}" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Late Grace (minutes) <span class="text-danger">*</span></label>
                        <input type="number" name="late_grace_minutes" class="form-control" value="{{ old('late_grace_minutes', $s->late_grace_minutes) }}" required>
                      </div>
                    </div>
                    <div class="mb-3 mt-3">
                      <label class="form-label">Status</label>
                      <select name="is_active" class="form-select">
                        <option value="1" {{ $s->is_active ? 'selected' : '' }}>Active</option>
                        <option value="0" {{ !$s->is_active ? 'selected' : '' }}>Inactive</option>
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
          <tr><td colspan="9" class="text-center">No shifts yet. Add your first shift to get started.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $shifts->links() }}
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('shifts.store') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Add Shift</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Shift Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Morning Shift" required>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Code</label>
              <input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. MOR">
            </div>
            <div class="col-md-6">
              <label class="form-label">Color</label>
              <input type="color" name="color" class="form-control form-control-color" value="{{ old('color', '#e8622e') }}">
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label">Start Time <span class="text-danger">*</span></label>
              <input type="time" name="start_time" class="form-control" value="{{ old('start_time', '09:00') }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Time <span class="text-danger">*</span></label>
              <input type="time" name="end_time" class="form-control" value="{{ old('end_time', '17:00') }}" required>
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label">Break (minutes) <span class="text-danger">*</span></label>
              <input type="number" name="break_minutes" class="form-control" value="{{ old('break_minutes', 60) }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Late Grace (minutes) <span class="text-danger">*</span></label>
              <input type="number" name="late_grace_minutes" class="form-control" value="{{ old('late_grace_minutes', 15) }}" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Shift</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
