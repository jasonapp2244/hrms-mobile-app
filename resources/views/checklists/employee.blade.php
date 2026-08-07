@extends('layouts.app')
@section('title', $employee->full_name . ' — Checklist')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Checklist</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a></li>
      <li class="breadcrumb-item active">Checklist</li>
    </ol></nav></div>
  <div>
    <a href="{{ route('checklists.templates') }}" class="btn btn-outline-secondary"><i class="ti ti-settings me-1"></i>Edit standard steps</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="row">
  @foreach(\App\Models\ChecklistTemplate::KINDS as $kind => $label)
  @php
    $list = $items[$kind] ?? collect();
    $done = $list->filter->isDone()->count();
    $overdue = $list->filter->isOverdue()->count();
  @endphp
  <div class="col-lg-6 mb-3">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">
          <i class="ti ti-{{ $kind === 'onboarding' ? 'user-plus' : 'user-minus' }} me-1"></i>{{ $label }}
        </h5>
        <div class="d-flex align-items-center gap-2">
          @if($list->count())
            <span class="badge bg-{{ $done === $list->count() ? 'success' : 'light text-dark' }}">
              {{ $done }}/{{ $list->count() }}
            </span>
            @if($overdue)<span class="badge bg-danger">{{ $overdue }} overdue</span>@endif
          @endif
          <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#raise{{ $kind }}">
            {{ $list->count() ? 'Add missing steps' : 'Raise checklist' }}
          </button>
        </div>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @forelse($list as $item)
          <li class="list-group-item {{ $item->isOverdue() ? 'bg-danger-transparent' : '' }}">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="d-flex gap-2">
                <form action="{{ route('checklists.toggle', [$employee, $item]) }}" method="POST" class="mt-1">
                  @csrf
                  <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent"
                          title="{{ $item->isDone() ? 'Reopen this step' : 'Tick this step off' }}">
                    <i class="ti ti-{{ $item->isDone() ? 'checkbox text-success' : 'square' }} fs-20"></i>
                  </button>
                </form>
                <div>
                  <div class="{{ $item->isDone() ? 'text-decoration-line-through text-muted' : 'fw-semibold' }}">
                    {{ $item->title }}
                  </div>
                  @if($item->description)<div class="text-muted fs-12">{{ $item->description }}</div>@endif
                  <div class="text-muted fs-12">
                    @if($item->owner){{ $item->owner }} · @endif
                    @if($item->due_on)
                      due {{ $item->due_on->format('M j, Y') }}
                    @else
                      no date
                    @endif
                    @if($item->isDone())
                      · done {{ $item->completed_at->format('M j') }}
                      @if($item->completedBy) by {{ $item->completedBy->name }} @endif
                    @endif
                  </div>
                </div>
              </div>
              <form action="{{ route('checklists.items.destroy', [$employee, $item]) }}" method="POST"
                    onsubmit="return confirm('Remove this step from their checklist?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-x"></i></button>
              </form>
            </div>
          </li>
          @empty
          <li class="list-group-item text-center text-muted py-4">
            No {{ strtolower($label) }} checklist raised for {{ $employee->first_name }} yet.
          </li>
          @endforelse
        </ul>
      </div>
    </div>
  </div>

  <div class="modal fade" id="raise{{ $kind }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form action="{{ route('checklists.generate', $employee) }}" method="POST">
          @csrf
          <input type="hidden" name="kind" value="{{ $kind }}">
          <div class="modal-header">
            <h5 class="modal-title">{{ $label }} checklist for {{ $employee->full_name }}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-muted">
              Copies the company's current {{ strtolower($label) }} steps onto this person's
              record. Steps already on their list are skipped, so this is safe to press again
              after adding a new standard step.
            </p>
            <label class="form-label">
              {{ $kind === 'onboarding' ? 'Hire date' : 'Leaving date' }}
            </label>
            <input type="date" name="anchor_date" class="form-control"
                   value="{{ $kind === 'onboarding' ? $employee->hire_date?->toDateString() : '' }}">
            <div class="form-text">
              Due dates are counted from here. Leave it empty and the steps are raised with no
              dates rather than with wrong ones.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Raise checklist</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  @endforeach
</div>
@endsection
