@extends('layouts.app')
@section('title','Joining & Leaving Checklists')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Joining &amp; Leaving Checklists</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item">Employees</li>
      <li class="breadcrumb-item active">Checklists</li>
    </ol></nav></div>
  <div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStep">
      <i class="ti ti-plus me-1"></i>Add Step
    </button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
  <i class="ti ti-checklist me-1"></i>
  These are the steps your company always takes. Raising a checklist for somebody
  <strong>copies</strong> them onto that person's record, so editing a step here never
  rewrites what somebody was asked to do last year — and removing one leaves finished
  checklists intact. Timing is counted from the hire date, or from the leaving date you
  give when raising the list.
</div>

<div class="row">
  @foreach(['onboarding' => $onboarding, 'offboarding' => $offboarding] as $kind => $steps)
  <div class="col-lg-6 mb-3">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="ti ti-{{ $kind === 'onboarding' ? 'user-plus' : 'user-minus' }} me-1"></i>
          {{ \App\Models\ChecklistTemplate::KINDS[$kind] }}
        </h5>
        <span class="badge bg-light text-dark">{{ $steps->count() }} step(s)</span>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @forelse($steps as $step)
          <li class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-semibold">
                  {{ $step->title }}
                  @unless($step->is_active)<span class="badge bg-secondary ms-1">Paused</span>@endunless
                </div>
                @if($step->description)<div class="text-muted fs-12">{{ $step->description }}</div>@endif
                <div class="text-muted fs-12">
                  {{ $step->timing }}@if($step->owner) · {{ $step->owner }}@endif
                </div>
              </div>
              <div class="text-nowrap">
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#editStep{{ $step->id }}"><i class="ti ti-edit"></i></button>
                <form action="{{ route('checklists.templates.destroy', $step) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Remove this step? Checklists already raised keep it.');">
                  @csrf @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                </form>
              </div>
            </div>
          </li>

          <div class="modal fade" id="editStep{{ $step->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form action="{{ route('checklists.templates.update', $step) }}" method="POST">
                  @csrf @method('PUT')
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Step</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    @include('checklists.partials.step-fields', ['step' => $step, 'uid' => $step->id])
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          @empty
          <li class="list-group-item text-center text-muted py-4">
            No {{ strtolower(\App\Models\ChecklistTemplate::KINDS[$kind]) }} steps yet.
          </li>
          @endforelse
        </ul>
      </div>
    </div>
  </div>
  @endforeach
</div>

<div class="modal fade" id="addStep" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('checklists.templates.store') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Add Step</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          @include('checklists.partials.step-fields', ['step' => null, 'uid' => 'new'])
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Step</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
