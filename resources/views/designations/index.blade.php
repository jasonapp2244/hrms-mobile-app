@extends('layouts.app')
@section('title','Designations')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Designations</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Designations</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-plus me-1"></i>Add Designation</button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="card">
  <div class="card-header">
    <h5 class="mb-0">Designations</h5>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Name</th>
            <th>Department</th>
            <th>Employees</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($designations as $x)
          <tr>
            <td>{{ $x->name }}</td>
            <td>{{ $x->department->name ?? '—' }}</td>
            <td><span class="badge bg-light text-dark">{{ $x->employees_count }}</span></td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $x->id }}"><i class="ti ti-edit"></i></button>
              <form action="{{ route('designations.destroy', $x) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this designation?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="editModal{{ $x->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form action="{{ route('designations.update', $x) }}" method="POST">
                  @csrf
                  @method('PUT')
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Designation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label">Name <span class="text-danger">*</span></label>
                      <input type="text" name="name" class="form-control" value="{{ old('name', $x->name) }}" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Department</label>
                      <select name="department_id" class="form-select">
                        <option value="">None</option>
                        @foreach($departments as $dep)
                          <option value="{{ $dep->id }}" {{ (string) old('department_id', $x->department_id) === (string) $dep->id ? 'selected' : '' }}>{{ $dep->name }}</option>
                        @endforeach
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
            <td colspan="4" class="text-center">No designations found.</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $designations->links() }}
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('designations.store') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Add Designation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">None</option>
              @foreach($departments as $dep)
                <option value="{{ $dep->id }}" {{ (string) old('department_id') === (string) $dep->id ? 'selected' : '' }}>{{ $dep->name }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Designation</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
