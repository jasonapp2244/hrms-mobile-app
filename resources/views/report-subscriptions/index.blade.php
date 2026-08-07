@extends('layouts.app')
@section('title','Scheduled Reports')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Scheduled Reports</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item">Reports</li>
      <li class="breadcrumb-item active">Scheduled</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap gap-2">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-plus me-1"></i>Schedule a Report</button>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
  <i class="ti ti-info-circle me-1"></i>
  Each schedule emails a finished report to the addresses you list — a daily one covers
  <strong>yesterday</strong>, a weekly one <strong>last week</strong>, a monthly one
  <strong>last month</strong>. They go out at
  {{ sprintf('%02d:00', \App\Models\ReportSubscription::SEND_HOUR) }} in the company's timezone.
  Recipients do not need a login here. <strong>Send Now</strong> delivers one straight away so you
  can check it before trusting the schedule.
</div>

<div class="card">
  <div class="card-header"><h5 class="mb-0">Standing Orders</h5></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Report</th>
            <th>Frequency</th>
            <th>Format</th>
            <th>Office</th>
            <th>Recipients</th>
            <th>Last Sent</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($subscriptions as $s)
          <tr>
            <td>{{ $s->report_label }}</td>
            <td>{{ $s->frequency_label }}</td>
            <td><span class="badge bg-light text-dark text-uppercase">{{ $s->format }}</span></td>
            <td>{{ $s->office->name ?? 'All offices' }}</td>
            <td class="small">{{ implode(', ', $s->recipients) }}</td>
            <td>{{ $s->last_sent_at ? $s->last_sent_at->format('M j, Y H:i') : '—' }}</td>
            <td>
              @if($s->is_active)
                <span class="badge bg-success">Active</span>
              @else
                <span class="badge bg-secondary">Paused</span>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <form action="{{ route('report-subscriptions.send', $s) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success" title="Send now"><i class="ti ti-send"></i></button>
              </form>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $s->id }}"><i class="ti ti-edit"></i></button>
              <form action="{{ route('report-subscriptions.destroy', $s) }}" method="POST" class="d-inline" onsubmit="return confirm('Stop sending this report?');">
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
                <form action="{{ route('report-subscriptions.update', $s) }}" method="POST">
                  @csrf
                  @method('PUT')
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    @include('report-subscriptions.fields', ['s' => $s, 'uid' => $s->id, 'offices' => $offices])
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
          <tr><td colspan="8" class="text-center text-muted py-4">Nothing is being emailed on a schedule yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('report-subscriptions.store') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Schedule a Report</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          @include('report-subscriptions.fields', ['s' => null, 'uid' => 'new', 'offices' => $offices])
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Schedule</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
