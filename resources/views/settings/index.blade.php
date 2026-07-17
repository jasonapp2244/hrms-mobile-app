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
        <h5 class="mb-0"><i class="ti ti-qrcode me-1"></i>Attendance QR</h5>
      </div>
      <div class="card-body">
        <table class="table mb-2">
          <tbody>
            <tr>
              <th style="width:50%">Rotation Window</th>
              <td>{{ $qrWindow }} seconds</td>
            </tr>
            <tr>
              <th>Grace Windows</th>
              <td>{{ $qrGrace }}</td>
            </tr>
          </tbody>
        </table>
        <p class="text-muted mb-0"><small>These values are configured in <code>App\Services\QrTokenService</code>.</small></p>
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
            <tr>
              <th>Timezone</th>
              <td>{{ config('app.timezone') }}</td>
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
        <h5 class="mb-0"><i class="ti ti-map-2 me-1"></i>Phase / Roadmap</h5>
      </div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <li class="mb-2">
            <span class="badge bg-success me-2">Active</span>
            Phase 1 &mdash; Admin dashboard + Attendance
          </li>
          <li class="mb-2">
            <span class="badge bg-secondary me-2">Coming soon</span>
            Leave Management
          </li>
          <li class="mb-2">
            <span class="badge bg-secondary me-2">Coming soon</span>
            Shift / Schedule
          </li>
          <li class="mb-2">
            <span class="badge bg-secondary me-2">Coming soon</span>
            AI Assistant
          </li>
          <li class="mb-0">
            <span class="badge bg-secondary me-2">Coming soon</span>
            Mobile Apps
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>
@endsection
