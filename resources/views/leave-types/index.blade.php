@extends('layouts.app')
@section('title','Leave Types')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Leave Types</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Leave Types</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap gap-2">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-plus me-1"></i>Add Leave Type</button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
  <i class="ti ti-info-circle me-1"></i>Define the kinds of leave staff can request. <strong>Days / Year</strong> is the default entitlement each employee receives. <strong>Carry Forward</strong> caps how many unused days roll into next year — leave it empty for no cap, or set 0 to stop carry-over entirely.
</div>

<div class="card">
  <div class="card-header"><h5 class="mb-0">Leave Types</h5></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Leave Type</th>
            <th>Code</th>
            <th>Days / Year</th>
            <th>Paid</th>
            <th>Approval</th>
            <th>Half Day</th>
            <th>Carry Forward</th>
            <th>Requests</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($leaveTypes as $t)
          <tr>
            <td>
              <span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:{{ $t->color }}"></span>
              {{ $t->name }}
            </td>
            <td>{{ $t->code ?? '—' }}</td>
            <td>{{ rtrim(rtrim(number_format($t->days_per_year, 1), '0'), '.') }}</td>
            <td>
              @if($t->is_paid)
                <span class="badge bg-success">Paid</span>
              @else
                <span class="badge bg-secondary">Unpaid</span>
              @endif
            </td>
            <td>{{ $t->requires_approval ? 'Required' : 'Automatic' }}</td>
            <td>{{ $t->allow_half_day ? 'Allowed' : 'No' }}</td>
            <td>
              @if(is_null($t->carry_forward_max))
                <span class="text-muted">Unlimited</span>
              @elseif((float) $t->carry_forward_max == 0.0)
                <span class="text-muted">None</span>
              @else
                {{ rtrim(rtrim(number_format($t->carry_forward_max, 1), '0'), '.') }} days
              @endif
            </td>
            <td><span class="badge bg-light text-dark">{{ $t->requests_count }}</span></td>
            <td>
              @if($t->is_active)
                <span class="badge bg-success">Active</span>
              @else
                <span class="badge bg-danger">Inactive</span>
              @endif
            </td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $t->id }}"><i class="ti ti-edit"></i></button>
              @if($t->requests_count > 0)
                {{-- Deleting is blocked once leave exists under this type; the
                     controller refuses it, so the button is disabled up front. --}}
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                        title="In use by {{ $t->requests_count }} request(s) — set to Inactive instead"><i class="ti ti-trash"></i></button>
              @else
                <form action="{{ route('leave-types.destroy', $t) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this leave type?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                </form>
              @endif
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="editModal{{ $t->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form action="{{ route('leave-types.update', $t) }}" method="POST">
                  @csrf
                  @method('PUT')
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Leave Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label">Name <span class="text-danger">*</span></label>
                      <input type="text" name="name" class="form-control" value="{{ old('name', $t->name) }}" required>
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" class="form-control" value="{{ old('code', $t->code) }}">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Colour</label>
                        <input type="color" name="color" class="form-control form-control-color" value="{{ old('color', $t->color) }}">
                      </div>
                    </div>
                    <div class="row g-3 mt-0">
                      <div class="col-md-6">
                        <label class="form-label">Days / Year <span class="text-danger">*</span></label>
                        <input type="number" step="0.5" min="0" max="365" name="days_per_year" class="form-control" value="{{ old('days_per_year', rtrim(rtrim(number_format($t->days_per_year, 1), '0'), '.')) }}" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Carry Forward Max</label>
                        <input type="number" step="0.5" min="0" max="365" name="carry_forward_max" class="form-control" value="{{ old('carry_forward_max', $t->carry_forward_max) }}" placeholder="Empty = unlimited">
                      </div>
                    </div>
                    <div class="row g-3 mt-0">
                      <div class="col-md-12">
                        <label class="form-label">Entitlement arrives</label>
                        <select name="accrual_mode" class="form-select">
                          @foreach(\App\Models\LeaveType::ACCRUAL_MODES as $mode => $label)
                            <option value="{{ $mode }}" @selected(old('accrual_mode', $t->accrual_mode) === $mode)>{{ $label }}</option>
                          @endforeach
                        </select>
                        <div class="form-text">Monthly stops a November starter booking the whole year in December.</div>
                      </div>
                    </div>
                    <div class="mt-3">
                      {{-- Hidden partner sends 0 when the box is unchecked, so
                           clearing a flag actually persists. --}}
                      <div class="form-check">
                        <input type="hidden" name="is_paid" value="0">
                        <input class="form-check-input" type="checkbox" name="is_paid" value="1" id="paid{{ $t->id }}" @checked($t->is_paid)>
                        <label class="form-check-label" for="paid{{ $t->id }}">Paid leave</label>
                      </div>
                      <div class="form-check">
                        <input type="hidden" name="requires_approval" value="0">
                        <input class="form-check-input" type="checkbox" name="requires_approval" value="1" id="appr{{ $t->id }}" @checked($t->requires_approval)>
                        <label class="form-check-label" for="appr{{ $t->id }}">Requires approval</label>
                      </div>
                      <div class="form-check">
                        <input type="hidden" name="allow_half_day" value="0">
                        <input class="form-check-input" type="checkbox" name="allow_half_day" value="1" id="half{{ $t->id }}" @checked($t->allow_half_day)>
                        <label class="form-check-label" for="half{{ $t->id }}">Allow half days</label>
                      </div>
                      <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="act{{ $t->id }}" @checked($t->is_active)>
                        <label class="form-check-label" for="act{{ $t->id }}">Active</label>
                      </div>
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
          <tr><td colspan="10" class="text-center">No leave types yet. Add your first one to get started.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $leaveTypes->links() }}
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('leave-types.store') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Add Leave Type</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Annual Leave" required>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Code</label>
              <input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. AL">
            </div>
            <div class="col-md-6">
              <label class="form-label">Colour</label>
              <input type="color" name="color" class="form-control form-control-color" value="{{ old('color', '#F26522') }}">
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label">Days / Year <span class="text-danger">*</span></label>
              <input type="number" step="0.5" min="0" max="365" name="days_per_year" class="form-control" value="{{ old('days_per_year', 20) }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Carry Forward Max</label>
              <input type="number" step="0.5" min="0" max="365" name="carry_forward_max" class="form-control" value="{{ old('carry_forward_max') }}" placeholder="Empty = unlimited">
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-12">
              <label class="form-label">Entitlement arrives</label>
              <select name="accrual_mode" class="form-select">
                @foreach(\App\Models\LeaveType::ACCRUAL_MODES as $mode => $label)
                  <option value="{{ $mode }}" @selected(old('accrual_mode') === $mode)>{{ $label }}</option>
                @endforeach
              </select>
              <div class="form-text">Monthly stops a November starter booking the whole year in December.</div>
            </div>
          </div>
          <div class="mt-3">
            <div class="form-check">
              <input type="hidden" name="is_paid" value="0">
              <input class="form-check-input" type="checkbox" name="is_paid" value="1" id="addPaid" checked>
              <label class="form-check-label" for="addPaid">Paid leave</label>
            </div>
            <div class="form-check">
              <input type="hidden" name="requires_approval" value="0">
              <input class="form-check-input" type="checkbox" name="requires_approval" value="1" id="addAppr" checked>
              <label class="form-check-label" for="addAppr">Requires approval</label>
            </div>
            <div class="form-check">
              <input type="hidden" name="allow_half_day" value="0">
              <input class="form-check-input" type="checkbox" name="allow_half_day" value="1" id="addHalf" checked>
              <label class="form-check-label" for="addHalf">Allow half days</label>
            </div>
            <div class="form-check">
              <input type="hidden" name="is_active" value="0">
              <input class="form-check-input" type="checkbox" name="is_active" value="1" id="addAct" checked>
              <label class="form-check-label" for="addAct">Active</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Leave Type</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
