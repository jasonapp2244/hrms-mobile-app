@extends('layouts.app')
@section('title', $employee->full_name . ' — Documents')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Documents</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a></li>
      <li class="breadcrumb-item active">Documents</li>
    </ol></nav></div>
  <div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
      <i class="ti ti-upload me-1"></i>Upload Document
    </button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
  <i class="ti ti-lock me-1"></i>
  Files are held privately and are only reachable through this page — they are never
  served from a public URL. Anything with an expiry date is chased automatically:
  whoever manages employee records is emailed {{ \App\Models\EmployeeDocument::WARN_DAYS }} days before it lapses.
</div>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Document</th>
            <th>Type</th>
            <th>Issued</th>
            <th>Expires</th>
            <th>Size</th>
            <th>Uploaded by</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($documents as $doc)
          <tr>
            <td>
              <div class="fw-semibold">{{ $doc->title }}</div>
              <div class="text-muted fs-12">{{ $doc->original_name }}</div>
              @if($doc->notes)<div class="text-muted fs-12">{{ $doc->notes }}</div>@endif
            </td>
            <td><span class="badge bg-light text-dark">{{ $doc->type_label }}</span></td>
            <td class="fs-13">{{ $doc->issued_on?->format('M j, Y') ?? '—' }}</td>
            <td>
              @switch($doc->expiry_state)
                @case('expired')
                  <span class="badge bg-danger">Expired {{ $doc->expires_on->format('M j, Y') }}</span>
                  @break
                @case('soon')
                  <span class="badge bg-warning text-dark">{{ $doc->expires_on->format('M j, Y') }}</span>
                  @break
                @case('valid')
                  <span class="fs-13">{{ $doc->expires_on->format('M j, Y') }}</span>
                  @break
                @default
                  <span class="text-muted fs-13">No expiry</span>
              @endswitch
            </td>
            <td class="fs-13 text-muted">{{ $doc->size_label }}</td>
            <td class="fs-13 text-muted">{{ $doc->uploader->name ?? '—' }}</td>
            <td class="text-end text-nowrap">
              <a href="{{ route('employees.documents.download', [$employee, $doc]) }}"
                 class="btn btn-sm btn-outline-primary"><i class="ti ti-download"></i></a>
              <form action="{{ route('employees.documents.destroy', [$employee, $doc]) }}" method="POST"
                    class="d-inline" onsubmit="return confirm('Delete this document? The file is removed too.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
          @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Nothing filed for {{ $employee->first_name }} yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('employees.documents.store', $employee) }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Upload Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Type <span class="text-danger">*</span></label>
              <select name="type" class="form-select" required>
                @foreach(\App\Models\EmployeeDocument::TYPES as $key => $label)
                  <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" value="{{ old('title') }}"
                     placeholder="e.g. Employment contract 2026" required>
            </div>
            <div class="col-12">
              <label class="form-label">File <span class="text-danger">*</span></label>
              <input type="file" name="file" class="form-control" required
                     accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx">
              <div class="form-text">PDF, image or Office document, up to 10&nbsp;MB.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Issued on</label>
              <input type="date" name="issued_on" class="form-control" value="{{ old('issued_on') }}">
            </div>
            <div class="col-md-6">
              <label class="form-label">Expires on</label>
              <input type="date" name="expires_on" class="form-control" value="{{ old('expires_on') }}">
              <div class="form-text">Leave empty if it does not expire. A date here starts the reminders.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
