@extends('layouts.app')
@section('title','QR Screens')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2"><h2 class="mb-1">QR Check-in Screens</h2>
		<nav><ol class="breadcrumb mb-0">
			<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
			<li class="breadcrumb-item">Attendance</li>
			<li class="breadcrumb-item active">QR screens</li>
		</ol></nav></div>
	<div class="d-flex align-items-center flex-wrap gap-2">
		<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qrLaunchModal">
			<i class="ti ti-qrcode me-1"></i>Open QR screen
		</button>
		@if($required)
			<span class="badge bg-success">QR check-in is on</span>
		@else
			<span class="badge bg-secondary">QR check-in is off</span>
		@endif
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

{{-- A4.21. What the screen is, what staff do with it, and the order to switch
     things on in — the policy refuses the button, so a screen has to exist first. --}}
<div class="alert alert-info">
	<i class="ti ti-info-circle me-1"></i>
	Put a tablet, spare phone, laptop or TV at the office and open a screen's link on it. It shows a QR code
	that <strong>changes after every scan</strong> and on its own every {{ \App\Services\QrAttendanceService::TOKEN_SECONDS }} seconds.
	Staff open the app, tap <strong>Scan to check in</strong> and point their phone at it; the system decides
	whether it is a check-in or a check-out. A photo of the screen is useless — the code has already changed.
	@unless($required)
		<div class="mt-2 mb-0">
			Staff can still tap the button until you switch on
			{{-- HR run this screen but do not hold manage-settings; a link they would 403 on is worse than a name. --}}
			@can('manage-settings')
				<a href="{{ route('policies.edit') }}">“Office staff scan the office QR code”</a> in policies —
				do that once a screen is up.
			@else
				<strong>“Office staff scan the office QR code”</strong> in policies —
				an administrator can do that once a screen is up.
			@endcan
		</div>
	@endunless
</div>

<div class="card mb-3">
	<div class="card-header"><h5 class="mb-0">Add a screen</h5></div>
	<div class="card-body">
		@if($offices->isEmpty())
			<p class="text-muted mb-0">There are no active offices yet. An administrator adds them under Offices.</p>
		@else
			<form method="POST" action="{{ route('attendance.qr-displays.store') }}" class="row g-2 align-items-end">
				@csrf
				<div class="col-md-4">
					<label class="form-label">Office</label>
					<select name="office_id" class="form-select" required>
						@foreach($offices as $office)
							<option value="{{ $office->id }}" @selected(old('office_id') == $office->id)>{{ $office->name }}</option>
						@endforeach
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label">Screen name</label>
					<input type="text" name="name" class="form-control" maxlength="100"
						value="{{ old('name') }}" placeholder="Optional — e.g. Front desk tablet">
				</div>
				<div class="col-md-4">
					<button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Create screen</button>
				</div>
			</form>
		@endif
	</div>
</div>

<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="d-flex align-items-center">
			<input type="hidden" name="show_revoked" value="0">
			<label class="btn btn-light mb-0">
				<input type="checkbox" name="show_revoked" value="1" class="form-check-input me-1"
					onchange="this.form.submit()" {{ request()->boolean('show_revoked') ? 'checked' : '' }}>
				Show switched-off screens
			</label>
		</form>
	</div>
</div>

<div class="card">
	<div class="card-body p-0">
		<div class="table-responsive">
			<table class="table table-hover mb-0">
				<thead>
					<tr>
						<th>Screen</th>
						<th>Office</th>
						<th>Last seen</th>
						<th>Status</th>
						<th class="text-end">Action</th>
					</tr>
				</thead>
				<tbody>
					@forelse($displays as $display)
					<tr>
						<td>
							<strong>{{ $display->name }}</strong>
							<div class="text-muted small">
								Added {{ \App\Support\Clock::local($display->created_at)?->format('M j, Y') }}
								@if($display->createdBy) by {{ $display->createdBy->name }}@endif
							</div>
						</td>
						<td>{{ $display->office?->name ?? '—' }}</td>
						<td>
							@if($display->last_seen_at)
								{{ $display->last_seen_at->diffForHumans() }}
							@else
								<span class="text-muted">Never opened</span>
							@endif
						</td>
						<td>
							@if($display->isRevoked())
								<span class="badge bg-secondary">Switched off</span>
							@elseif($display->last_seen_at && $display->last_seen_at->gt(now()->subMinute()))
								<span class="badge bg-success">Showing codes</span>
							@else
								<span class="badge bg-warning text-dark">Not open</span>
							@endif
						</td>
						<td class="text-end">
							@unless($display->isRevoked())
								<a href="{{ $display->url() }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary">
									<i class="ti ti-external-link me-1"></i>Open screen
								</a>
								<button type="button" class="btn btn-sm btn-light" data-copy="{{ $display->url() }}"
									onclick="navigator.clipboard.writeText(this.dataset.copy).then(() => { this.textContent = 'Copied'; })">
									<i class="ti ti-copy me-1"></i>Copy link
								</button>
								<form action="{{ route('attendance.qr-displays.revoke', $display) }}" method="POST" class="d-inline"
									onsubmit="return confirm('Switch off {{ $display->name }}? Its link will stop working.');">
									@csrf
									<button type="submit" class="btn btn-sm btn-outline-danger">
										<i class="ti ti-power me-1"></i>Switch off
									</button>
								</form>
							@endunless
						</td>
					</tr>
					@empty
					<tr><td colspan="5" class="text-center text-muted py-4">No screens yet. Add one above.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
	@if($displays->hasPages())
		<div class="card-footer">{{ $displays->links() }}</div>
	@endif
</div>

@include('attendance.partials.qr-launch-modal')
@endsection
