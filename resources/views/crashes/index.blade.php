@extends('layouts.app')
@section('title','App Crash Reports')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">App Crash Reports</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Crash Reports</li>
    </ol></nav></div>
  @if($total > 0)
  <div class="my-auto mb-2">
    <form method="POST" action="{{ route('crashes.destroy') }}"
          onsubmit="return confirm('Clear every crash report older than 90 days?');">
      @csrf @method('DELETE')
      <input type="hidden" name="days" value="90">
      <button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash me-1"></i>Clear older than 90 days</button>
    </form>
  </div>
  @endif
</div>

@if(session('status'))
  <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if(session('error'))
  <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="alert alert-info">
  <i class="ti ti-bug me-1"></i>
  Crashes the mobile app did not survive, grouped by where they happened — one row
  per bug, however many handsets hit it. Reports are written on the phone at the
  moment of the crash and delivered on its next launch, so <strong>&ldquo;last
  seen&rdquo; is when it happened, not when it arrived</strong>. They go to this
  server and nowhere else: there is no third-party crash service and no analytics.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Platform</label>
        <select name="platform" class="form-select" onchange="this.form.submit()">
          <option value="">Both</option>
          @foreach($platforms as $p)
            <option value="{{ $p }}" @selected(request('platform')===$p)>{{ ucfirst($p) }}</option>
          @endforeach
        </select>
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
            <th>What broke</th>
            <th class="text-center">Reports</th>
            <th>Builds</th>
            <th>First seen</th>
            <th>Last seen</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($groups as $g)
          <tr>
            <td>
              <a href="{{ route('crashes.index', ['fingerprint' => $g->fingerprint]) }}"
                 class="fw-semibold text-body">{{ $g->exception }}</a>
              @if($g->message)
                <div class="text-muted fs-12 text-truncate" style="max-width: 42rem;">{{ $g->message }}</div>
              @endif
            </td>
            <td class="text-center">
              <span class="badge bg-{{ $g->hits >= 10 ? 'danger' : ($g->hits >= 3 ? 'warning' : 'secondary') }}">{{ $g->hits }}</span>
            </td>
            <td class="fs-13">
              {{ $g->newest_version ?? '—' }}
              @if($g->versions > 1)
                <div class="text-muted fs-12">and {{ $g->versions - 1 }} older</div>
              @endif
            </td>
            <td class="fs-13 text-muted text-nowrap">
              {{ $g->first_seen ? \Illuminate\Support\Carbon::parse($g->first_seen)->format('M j, Y H:i') : '—' }}
            </td>
            <td class="fs-13 text-nowrap">
              {{ $g->last_seen ? \Illuminate\Support\Carbon::parse($g->last_seen)->format('M j, Y H:i') : '—' }}
            </td>
            <td class="text-end">
              <a href="{{ route('crashes.index', ['fingerprint' => $g->fingerprint]) }}"
                 class="btn btn-sm btn-light">Open</a>
            </td>
          </tr>
          @empty
          <tr><td colspan="6" class="text-center text-muted py-4">
            No crashes reported. That is the reading to hope for.
          </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $groups->links() }}
  </div>
</div>
@endsection
