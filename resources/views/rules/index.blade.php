@extends('layouts.app')
@section('title','Policy Rules')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Policy Rules</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item">Settings</li>
      <li class="breadcrumb-item active">Rules</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap gap-2">
    <a href="{{ route('policies.edit') }}" class="btn btn-outline-secondary"><i class="ti ti-calendar-cog me-1"></i>Working Week &amp; Policies</a>
    <a href="{{ route('rules.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Add Rule</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="alert alert-info">
  <i class="ti ti-info-circle me-1"></i>
  A rule watches for something and tells somebody about it — <em>&ldquo;when anyone in Ops clocks
  in more than twenty minutes late, notify their manager&rdquo;</em>. The settings next door apply
  one value to the whole company; these are the exceptions that value cannot express.
  <strong>Rules never change a punch or decide a leave request</strong> — they notify, and they
  record. What they watch is decided when it happens, so a new rule says nothing about last week.
</div>

<div class="card">
  <div class="card-header"><h5 class="mb-0">Rules</h5></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Rule</th>
            <th>When</th>
            <th>If</th>
            <th>Then</th>
            <th>Last fired</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rules as $rule)
          <tr class="{{ $rule->is_active ? '' : 'opacity-50' }}">
            <td>
              <div class="fw-semibold">{{ $rule->name }}</div>
              @if($rule->author)
                <div class="text-muted small">Written by {{ $rule->author->name }}</div>
              @endif
            </td>
            <td>{{ $triggers[$rule->trigger] ?? $rule->trigger }}</td>
            <td class="text-muted">{{ $rule->summary($lookups) }}</td>
            <td class="text-muted">{{ $rule->actionSummary() }}</td>
            <td>
              @if($rule->last_fired_at)
                <span title="{{ $rule->last_fired_at }}">{{ $rule->last_fired_at->diffForHumans() }}</span>
              @else
                {{-- Never is worth saying plainly. A rule that has not fired
                     is either wrong or unnecessary, and the two look the same
                     until somebody notices the column. --}}
                <span class="text-muted">Never</span>
              @endif
            </td>
            <td>
              @if($rule->is_active)
                <span class="badge bg-success">On</span>
              @else
                <span class="badge bg-secondary">Off</span>
              @endif
            </td>
            <td class="text-end">
              <a href="{{ route('rules.edit', $rule) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="ti ti-edit"></i></a>
              <form action="{{ route('rules.toggle', $rule) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary"
                        title="{{ $rule->is_active ? 'Switch off' : 'Switch on' }}">
                  <i class="ti {{ $rule->is_active ? 'ti-player-pause' : 'ti-player-play' }}"></i>
                </button>
              </form>
              <form action="{{ route('rules.destroy', $rule) }}" method="POST" class="d-inline"
                    onsubmit="return confirm('Delete this rule? Switching it off keeps the wording.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
          @empty
          <tr><td colspan="7" class="text-center text-muted py-4">
            No rules yet. The company policies still apply — a rule is for the case a single
            setting cannot state.
          </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $rules->links() }}
  </div>
</div>
@endsection
