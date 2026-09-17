@extends('layouts.app')
@section('title','Trusted Phones')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2"><h2 class="mb-1">Trusted Phones</h2>
		<nav><ol class="breadcrumb mb-0">
			<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
			<li class="breadcrumb-item">Employees</li>
			<li class="breadcrumb-item active">Trusted phones</li>
		</ol></nav></div>
	<div class="d-flex align-items-center flex-wrap gap-2">
		<span class="badge bg-primary-transparent text-primary">{{ $active }} bound</span>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif

{{-- B1.6. The two things somebody standing at this screen needs to know: what
     the list is for, and what pressing the button does. The second sentence is
     the one that matters — Release is the whole reason this screen exists, and
     without it the policy is a trap. --}}
<div class="alert alert-info">
	<i class="ti ti-info-circle me-1"></i>
	Each account is claimed by the first phone it signs in on, and a second phone is then
	refused — which is what stops somebody lending a colleague their password to be clocked in.
	<strong>Release</strong> a phone when somebody loses one, replaces one, or reinstalls the app:
	their next sign-in claims the new one. Nothing here affects the web dashboard.
	@unless($active)
		<div class="mt-2 mb-0 text-muted">
			Nothing is bound yet. This list fills as people sign in
			@unless(auth()->user()->company?->policy('enforce_device_binding'))
				— though binding is currently <strong>off</strong>, so nobody is being refused.
				Switch it on in <a href="{{ route('policies.edit') }}">policies</a>.
			@endunless
		</div>
	@endunless
</div>

<div class="card mb-3">
	<div class="card-body">
		<form method="GET" class="row g-2 align-items-end">
			<div class="col-md-4">
				<label class="form-label">Search</label>
				<input type="text" name="q" class="form-control" value="{{ request('q') }}"
					placeholder="Name or email">
			</div>
			<div class="col-md-8 d-flex">
				<button type="submit" class="btn btn-primary me-2"><i class="ti ti-search me-1"></i>Search</button>
				<input type="hidden" name="show_released" value="0">
				<label class="btn btn-light me-2 mb-0" title="Phones that were released, kept as history">
					<input type="checkbox" name="show_released" value="1" class="form-check-input me-1"
						onchange="this.form.submit()" {{ request()->boolean('show_released') ? 'checked' : '' }}>
					Released
				</label>
				<a href="{{ route('devices.index') }}" class="btn btn-light">Clear</a>
			</div>
		</form>
	</div>
</div>

<div class="card">
	<div class="card-body p-0">
		<div class="table-responsive">
			<table class="table table-hover mb-0">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Phone</th>
						<th>Bound</th>
						<th>Last seen</th>
						<th>Status</th>
						<th class="text-end">Action</th>
					</tr>
				</thead>
				<tbody>
					@forelse($devices as $device)
					<tr>
						<td>
							<strong>{{ $device->user?->name ?? '—' }}</strong>
							<div class="text-muted small">{{ $device->user?->email }}</div>
						</td>
						<td>
							{{ $device->device_name ?: 'Unnamed device' }}
							@if($device->platform)
								<span class="badge bg-light text-dark ms-1">{{ $device->platform }}</span>
							@endif
							{{-- Never the device id itself. It is the value the binding turns
							     on, and printing it on a screen is the one way to make it
							     guessable. --}}
						</td>
						<td>{{ $device->trusted_at?->format('M j, Y') ?? '—' }}</td>
						<td>
							@if($device->last_seen_at)
								{{ $device->last_seen_at->diffForHumans() }}
								<div class="text-muted small">{{ $device->last_seen_at->format('M j, Y H:i') }}</div>
							@else
								<span class="text-muted">—</span>
							@endif
						</td>
						<td>
							@if($device->isReleased())
								<span class="badge bg-secondary">Released</span>
								<div class="text-muted small">
									{{ $device->released_at->format('M j, Y') }}
									@if($device->releasedBy) by {{ $device->releasedBy->name }}@endif
								</div>
							@else
								<span class="badge bg-success">Bound</span>
							@endif
						</td>
						<td class="text-end">
							@unless($device->isReleased())
								<form action="{{ route('devices.release', $device) }}" method="POST" class="d-inline"
									onsubmit="return confirm('Release {{ $device->user?->name }}\'s phone? Their next sign-in will claim whichever phone they use.');">
									@csrf
									<button type="submit" class="btn btn-sm btn-outline-danger">
										<i class="ti ti-device-mobile-off me-1"></i>Release
									</button>
								</form>
							@endunless
						</td>
					</tr>
					@empty
					<tr><td colspan="6" class="text-center text-muted py-4">
						No phones {{ request()->boolean('show_released') ? 'on record' : 'bound' }} yet.
					</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
	@if($devices->hasPages())
		<div class="card-footer">{{ $devices->links() }}</div>
	@endif
</div>
@endsection
