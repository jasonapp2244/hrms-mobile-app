@extends('layouts.app')
@section('title','Crash Detail')
@section('content')
@php($first = $reports->first())
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">{{ $first->exception ?? 'Crash' }}</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('crashes.index') }}">Crash Reports</a></li>
      <li class="breadcrumb-item active">Detail</li>
    </ol></nav></div>
  @if($first)
  <div class="my-auto mb-2">
    <form method="POST" action="{{ route('crashes.destroy') }}"
          onsubmit="return confirm('Clear every report for this crash?');">
      @csrf @method('DELETE')
      <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
      <button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash me-1"></i>Clear this crash</button>
    </form>
  </div>
  @endif
</div>

@if(! $first)
  <div class="alert alert-secondary">These reports have been cleared.</div>
@else

<div class="card mb-3">
  <div class="card-body">
    <div class="text-muted fs-12 text-uppercase mb-1">Message</div>
    <div class="mb-3">{{ $first->message ?: 'The exception carried no message.' }}</div>

    <div class="text-muted fs-12 text-uppercase mb-1">Stack, most recent report</div>
    <pre class="bg-light border rounded p-3 mb-0 fs-12" style="white-space: pre-wrap; max-height: 26rem; overflow: auto;">{{ $first->stack ?: 'No stack was captured.' }}</pre>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>When it happened</th>
            <th>Who</th>
            <th>Build</th>
            <th>Platform</th>
            <th>OS</th>
            <th>Arrived</th>
          </tr>
        </thead>
        <tbody>
          @foreach($reports as $r)
          <tr>
            <td class="text-nowrap">{{ $r->occurred_at?->format('M j, Y H:i:s') ?? '—' }}</td>
            <td class="fs-13">
              {{-- No user at all is the interesting case, not a gap: it means the
                   app crashed before anybody signed in. --}}
              {{ $r->user?->name ?? 'Before sign-in' }}
            </td>
            <td class="fs-13">{{ $r->app_version ?? '—' }}</td>
            <td class="fs-13">{{ $r->platform ? ucfirst($r->platform) : '—' }}</td>
            <td class="fs-13 text-muted text-break" style="max-width: 18rem;">
              {{-- Platform.operatingSystemVersion. On Android it names the build
                   and the handset, which is why no separate device field is
                   collected; on iOS it is the OS build alone. --}}
              {{ $r->os_version ?? '—' }}
            </td>
            <td class="fs-13 text-muted text-nowrap">{{ $r->created_at?->format('M j, H:i') ?? '—' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    {{ $reports->links() }}
  </div>
</div>
@endif
@endsection
