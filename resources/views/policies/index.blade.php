@extends('layouts.app')
@section('title','Working Week & Policies')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Working Week &amp; Policies</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item">Settings</li>
      <li class="breadcrumb-item active">Policies</li>
    </ol></nav></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<form method="POST" action="{{ route('policies.update') }}">
  @csrf
  @method('PUT')

  <div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Working Week</h5></div>
    <div class="card-body">
      <p class="text-muted">
        Tick the days your company does <strong>not</strong> work. These days are free —
        leave booked across them is not charged for, nobody is marked absent on them, and
        the roster leaves them blank. Changing this changes how existing leave is counted
        in reports, so it is worth getting right once rather than often.
      </p>
      <div class="d-flex flex-wrap gap-3">
        @foreach($days as $number => $name)
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="weekend_days[]" value="{{ $number }}"
                 id="day{{ $number }}" @checked(in_array($number, old('weekend_days', $weekend)))>
          <label class="form-check-label" for="day{{ $number }}">{{ $name }}</label>
        </div>
        @endforeach
      </div>
      <div class="form-text mt-2">Tick nothing if your company works every day of the week.</div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Default Day</h5></div>
    <div class="card-body">
      <p class="text-muted small">
        Used only when nobody rostered a shift — an unplanned day, or somebody who
        comes in on their day off. A shift on the roster always wins, and carries its
        own hours and grace.
      </p>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label" for="default_day_start">Day starts</label>
          <input type="time" name="default_day_start" id="default_day_start" class="form-control"
                 value="{{ old('default_day_start', substr($company->policy('default_day_start'), 0, 5)) }}">
          <div class="form-text">Arriving after this, plus the grace below, is recorded as late.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="default_day_end">Day ends</label>
          <input type="time" name="default_day_end" id="default_day_end" class="form-control"
                 value="{{ old('default_day_end', substr($company->policy('default_day_end'), 0, 5)) }}">
          <div class="form-text">Leaving before this is recorded as an early leave.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="default_day_grace_minutes">Grace</label>
          <div class="input-group">
            <input type="number" min="0" max="120" name="default_day_grace_minutes" id="default_day_grace_minutes"
                   class="form-control"
                   value="{{ old('default_day_grace_minutes', $company->policy('default_day_grace_minutes')) }}">
            <span class="input-group-text">minutes after the start</span>
          </div>
          <div class="form-text">Nobody is marked late inside this window.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Attendance</h5></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Clock-in reminder</label>
          <div class="input-group">
            <input type="number" min="0" max="120" name="checkin_reminder_before_minutes" class="form-control"
                   value="{{ old('checkin_reminder_before_minutes', $company->policy('checkin_reminder_before_minutes')) }}">
            <span class="input-group-text">minutes before the shift starts</span>
          </div>
          <div class="form-text">
            Push and the app's bell only, never email. <strong>0 switches it off</strong> — this is the
            one notification that arrives before the working day, on a personal phone.
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Missing-checkout reminder</label>
          <div class="input-group">
            <input type="number" min="0" max="1440" name="checkout_reminder_after_minutes" class="form-control"
                   value="{{ old('checkout_reminder_after_minutes', $company->policy('checkout_reminder_after_minutes')) }}">
            <span class="input-group-text">minutes after the shift ends</span>
          </div>
          <div class="form-text">Sent once, so nobody is nagged every quarter of an hour.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Auto-close an open day</label>
          <div class="input-group">
            <input type="number" min="0" max="1440" name="auto_close_after_minutes" class="form-control"
                   value="{{ old('auto_close_after_minutes', $company->policy('auto_close_after_minutes')) }}">
            <span class="input-group-text">minutes after the shift ends</span>
          </div>
          <div class="form-text">Generous is safer — closing a day somebody is still working understates their hours.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Location</label>
          <div class="form-check mt-2">
            <input type="hidden" name="enforce_geofence" value="0">
            <input class="form-check-input" type="checkbox" name="enforce_geofence" value="1" id="geo"
                   @checked(old('enforce_geofence', $company->policy('enforce_geofence')))>
            <label class="form-check-label" for="geo">Only accept punches inside the office radius</label>
          </div>
          <div class="form-text">
            Needs coordinates on the office. Staff set to WFH or hybrid are never blocked, and
            neither is a punch that arrives without a location — a phone indoors often cannot get one.
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Phones</label>
          <div class="form-check mt-2">
            <input type="hidden" name="enforce_device_binding" value="0">
            <input class="form-check-input" type="checkbox" name="enforce_device_binding" value="1" id="deviceBinding"
                   @checked(old('enforce_device_binding', $company->policy('enforce_device_binding')))>
            <label class="form-check-label" for="deviceBinding">Tie each account to one phone</label>
          </div>
          <div class="form-text">
            {{-- B1.6. The two things somebody needs to know before ticking it:
                 nobody is locked out today, and somebody will ring you when they
                 change phones. --}}
            Closes the oldest gap in attendance — lending a colleague your password so they
            can clock you in. Nobody is locked out when you switch this on: each account
            claims its own phone at its next sign-in, and a <em>second</em> phone is refused
            from then. A new or wiped phone needs releasing on the
            <a href="{{ route('devices.index') }}">trusted phones</a> list.
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Staff directory</label>
          <div class="form-check mt-2">
            <input type="hidden" name="directory_show_contact_details" value="0">
            <input class="form-check-input" type="checkbox" name="directory_show_contact_details" value="1" id="dirContact"
                   @checked(old('directory_show_contact_details', $company->policy('directory_show_contact_details')))>
            <label class="form-check-label" for="dirContact">Show colleagues' email and phone in the app</label>
          </div>
          <div class="form-text">
            Off by default. The directory always lists who works here, their job title, department
            and office; this decides whether staff can also see each other's contact details.
            There is one phone column on an employee record, so if it holds personal mobiles this
            publishes them to everybody — turn it on only if those numbers are work numbers.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Security</h5></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Sign out after inactivity</label>
          <div class="input-group">
            <input type="number" min="0" max="1440" name="session_idle_timeout_minutes" class="form-control"
                   value="{{ old('session_idle_timeout_minutes', $company->policy('session_idle_timeout_minutes')) }}">
            <span class="input-group-text">minutes</span>
          </div>
          <div class="form-text">0 turns it off. Counts idle time only — a long piece of work is never interrupted.</div>
        </div>
        <div class="col-md-8">
          <label class="form-label">Two-factor authentication</label>
          <div class="form-check mt-2">
            <input type="hidden" name="require_two_factor_for_staff" value="0">
            <input class="form-check-input" type="checkbox" name="require_two_factor_for_staff" value="1" id="tfa"
                   @checked(old('require_two_factor_for_staff', $company->policy('require_two_factor_for_staff')))>
            <label class="form-check-label" for="tfa">Require it on administrator and HR accounts</label>
          </div>
          <div class="form-text">
            Those accounts will be held at the setup screen until they have added an authenticator.
            Employees are unaffected — they clock in from a phone, and locking them out of that is not
            a security win. Anybody can switch it on for themselves from their profile either way.
          </div>
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Policies</button>
</form>
@endsection
