@extends('layouts.app')
@section('title','Settings')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Settings</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Settings</li>
    </ol></nav></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-clock-check me-1"></i>Attendance</h5>
      </div>
      <div class="card-body">
        <table class="table mb-2">
          <tbody>
            {{-- A4.21. Read from the policy, not written here: this card said
                 "Button" as a literal and would have gone on saying so. --}}
            @php($qrCheckin = (bool) auth()->user()->company?->policy('require_qr_checkin'))
            <tr>
              <th style="width:50%">Check-in Method</th>
              <td>
                @if($qrCheckin)
                  Office QR code for office staff; button for WFH and hybrid
                @else
                  Button (Check In / Check Out)
                @endif
              </td>
            </tr>
            <tr>
              <th>Works On</th>
              <td>{{ $qrCheckin ? 'Employee\'s own phone, scanning the office screen' : 'Employee\'s own mobile or PC' }}</td>
            </tr>
            <tr>
              <th>Location (GPS)</th>
              <td>Captured for record, does not block</td>
            </tr>
          </tbody>
        </table>
        <p class="text-muted mb-0"><small>Employees clock in/out from their self-service portal. Work mode (Office / WFH / Hybrid) is set per employee.</small></p>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-app-window me-1"></i>Application</h5>
      </div>
      <div class="card-body">
        <table class="table mb-0">
          <tbody>
            <tr>
              <th style="width:50%">App Name</th>
              <td>{{ config('app.name') }}</td>
            </tr>
            <tr>
              <th>Environment</th>
              <td>{{ app()->environment() }}</td>
            </tr>
            {{-- The company's zone is the one that matters: shifts, "today",
                 reports and the phone all follow it. This row used to print the
                 server's own UTC clock under the bare label "Timezone", which
                 read as though the system ran on UTC. --}}
            @php $settingsCompany = auth()->user()->company; @endphp
            <tr>
              <th>Company timezone</th>
              <td>
                {{ $settingsCompany?->tz() ?? config('app.timezone') }}
                @if($settingsCompany)
                  <span class="text-muted">· now {{ now($settingsCompany->tz())->format('h:i A') }}</span>
                  <a href="{{ route('company.index') }}" class="ms-1">Change</a>
                @endif
              </td>
            </tr>
            <tr>
              <th>Server clock</th>
              <td class="text-muted">{{ config('app.timezone') }} — times are converted to the company's zone</td>
            </tr>
            <tr>
              <th>Laravel Version</th>
              <td>{{ app()->version() }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-list-check me-1"></i>Phase / Roadmap</h5>
      </div>
      {{-- Driven from config/roadmap.php. The previous version was hard-coded
           here and drifted: it advertised Leave, Shift/Schedule and the mobile
           app as "coming soon" long after all three shipped, on the one screen
           a client is most likely to read. --}}
      @php($styles = config('roadmap.styles'))
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-borderless align-middle mb-0">
            <tbody>
              @foreach(config('roadmap.phases') as $phase)
              @php($style = $styles[$phase['status']] ?? $styles['planned'])
              <tr class="{{ $phase['status'] === 'planned' ? 'opacity-75' : '' }}">
                <td class="ps-0" style="width:1%; white-space:nowrap;">
                  <span class="text-muted small">Phase {{ $phase['no'] }}</span>
                </td>
                <td style="width:1%; white-space:nowrap;">
                  <span class="badge {{ $style['class'] }}">{{ $style['label'] }}</span>
                </td>
                <td>
                  <div class="fw-semibold">{{ $phase['title'] }}</div>
                  <div class="text-muted small">{{ $phase['detail'] }}</div>
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0 mt-2">
          A phase is marked delivered when its module works end to end. Feature-level
          detail lives in <code>Feature-List_Web-and-App.md</code>.
        </p>
      </div>
    </div>
  </div>
</div>
@endsection
