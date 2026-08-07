@extends('layouts.app')
@section('title','Activity & Security Log')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Activity &amp; Security Log</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Activity Log</li>
    </ol></nav></div>
</div>

<div class="alert alert-info">
  <i class="ti ti-shield-lock me-1"></i>
  Every sign-in, failed attempt, password change and settings change, newest first.
  Entries cannot be edited or removed — including by an administrator — so the record
  still means something after the fact. Attendance corrections have their own trail on
  the attendance log.
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Event</label>
        <select name="event" class="form-select">
          <option value="">All events</option>
          @foreach($events as $key => $meta)
            <option value="{{ $key }}" @selected(request('event')===$key)>{{ $meta['label'] }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">User</label>
        <select name="user_id" class="form-select">
          <option value="">Anyone</option>
          @foreach($users as $u)
            <option value="{{ $u->id }}" @selected((string)request('user_id')===(string)$u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" value="{{ request('from') }}" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" value="{{ request('to') }}" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Name or IP</label>
        <div class="input-group">
          <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search">
          <button class="btn btn-primary"><i class="ti ti-search"></i></button>
        </div>
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
            <th>When</th>
            <th>Event</th>
            <th>Who</th>
            <th>Detail</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          @forelse($logs as $log)
          <tr>
            <td class="text-nowrap">{{ $log->created_at?->format('M j, Y H:i:s') }}</td>
            <td><span class="badge bg-{{ $log->event_class }}">{{ $log->event_label }}</span></td>
            <td>
              {{ $log->actor_label ?? '—' }}
              @if($log->user && $log->user->name !== $log->actor_label)
                <div class="text-muted fs-12">{{ $log->user->name }}</div>
              @endif
            </td>
            <td class="fs-13">{{ $log->description ?? '—' }}</td>
            <td class="fs-13 text-muted">{{ $log->ip_address ?? '—' }}</td>
          </tr>
          @empty
          <tr><td colspan="5" class="text-center text-muted py-4">Nothing recorded for these filters.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    {{ $logs->links() }}
  </div>
</div>
@endsection
