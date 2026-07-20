@extends('layouts.employee')
@section('title', 'My Attendance')

@section('content')

	{{-- Greeting + today status --}}
	<div class="card mb-3">
		<div class="card-body d-flex align-items-center">
			<span class="emp-avatar me-3">{{ strtoupper(substr($employee->first_name, 0, 1)) }}</span>
			<div>
				<h5 class="mb-0">Hi, {{ $employee->first_name }}</h5>
				<small class="text-muted">
					{{ $employee->employee_code }}
					@if($employee->designation) &middot; {{ $employee->designation->name }} @endif
					@if($employee->office) &middot; {{ $employee->office->name }} @endif
				</small>
			</div>
			<div class="ms-auto text-end">
				@php
					$firstIn = $todayLogs->firstWhere('type', 'in');
					$lastOut = $todayLogs->where('type', 'out')->last();
				@endphp
				<div class="small text-muted">Today</div>
				<div>
					@if($firstIn)
						<span class="badge bg-success-transparent text-success">In {{ $firstIn->scanned_at->format('h:i A') }}</span>
					@endif
					@if($lastOut)
						<span class="badge bg-secondary-transparent">Out {{ $lastOut->scanned_at->format('h:i A') }}</span>
					@endif
					@if(!$firstIn)
						<span class="badge bg-warning-transparent text-warning">Not checked in</span>
					@endif
				</div>
			</div>
		</div>
	</div>

	{{-- Check in / out --}}
	<div class="card mb-3">
		<div class="card-header d-flex align-items-center justify-content-between">
			<h5 class="mb-0">
				@if($nextAction === 'in')
					<i class="ti ti-login me-1 text-success"></i>Check In
				@else
					<i class="ti ti-logout me-1 text-primary"></i>Check Out
				@endif
			</h5>
			<span class="badge bg-primary-transparent text-primary">Next: {{ strtoupper($nextAction) }}</span>
		</div>
		<div class="card-body text-center">
			<p class="text-muted mb-4">
				Tap the button below to record your {{ $nextAction === 'in' ? 'check-in' : 'check-out' }}.
				Works on your phone or computer.
			</p>

			<button id="check-btn"
				class="btn btn-lg {{ $nextAction === 'in' ? 'btn-success' : 'btn-primary' }} px-5 py-3"
				data-action="{{ $nextAction }}"
				style="min-width:220px;font-size:1.15rem;border-radius:14px">
				@if($nextAction === 'in')
					<i class="ti ti-login me-1"></i>Check In
				@else
					<i class="ti ti-logout me-1"></i>Check Out
				@endif
			</button>

			<div id="result" class="mt-3"></div>

			<div class="alert alert-info mt-4 mb-0 text-start" style="font-size:12.5px">
				<i class="ti ti-map-pin me-1"></i>
				Your time is recorded on our server. If your browser allows it, your location is
				also saved for HR — you are never blocked, so WFH and remote staff can check in from anywhere.
			</div>
		</div>
	</div>

	{{-- My attendance list --}}
	<div class="card">
		<div class="card-header"><h5 class="mb-0"><i class="ti ti-list-check me-1"></i>My Attendance</h5></div>
		<div class="card-body p-0">
			<div class="table-responsive">
				<table class="table mb-0 align-middle">
					<thead>
						<tr>
							<th>Date</th>
							<th>Type</th>
							<th>Time</th>
							<th>Status</th>
							<th>Office</th>
						</tr>
					</thead>
					<tbody>
						@forelse($logs as $log)
							<tr>
								<td>{{ $log->work_date->format('m/d/Y') }}</td>
								<td>
									@if($log->type === 'in')
										<span class="badge bg-success">IN</span>
									@else
										<span class="badge bg-dark">OUT</span>
									@endif
								</td>
								<td>{{ $log->scanned_at->format('h:i A') }}</td>
								<td>
									@if($log->status === 'late')
										<span class="badge bg-warning-transparent text-warning">late</span>
									@elseif($log->status === 'early_leave')
										<span class="badge bg-secondary-transparent">early leave</span>
									@else
										<span class="badge bg-success-transparent text-success">ontime</span>
									@endif
								</td>
								<td>{{ $log->office->name ?? '—' }}</td>
							</tr>
						@empty
							<tr><td colspan="5" class="text-center text-muted py-4">No attendance records yet.</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
			@if($logs->hasPages())
				<div class="p-3">{{ $logs->links() }}</div>
			@endif
		</div>
	</div>

@endsection

@push('scripts')
<script>
(function () {
	const checkUrl = "{{ route('employee.check') }}";
	const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
	const resultEl = document.getElementById('result');
	const btn = document.getElementById('check-btn');
	let busy = false;

	function show(type, msg) {
		resultEl.innerHTML = '<div class="alert alert-' + type + ' mb-0">' + msg + '</div>';
	}

	// Optional, free browser GPS. Resolves quickly with {} if unavailable or denied
	// — we never block the punch on location.
	function getGeo() {
		return new Promise((resolve) => {
			if (!navigator.geolocation) return resolve({});
			navigator.geolocation.getCurrentPosition(
				p => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude }),
				() => resolve({}),
				{ timeout: 4000, maximumAge: 60000 }
			);
		});
	}

	btn.addEventListener('click', async function () {
		if (busy) return;
		busy = true;
		const original = btn.innerHTML;
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Recording…';

		const geo = await getGeo();
		try {
			const res = await fetch(checkUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify({ latitude: geo.latitude, longitude: geo.longitude })
			});
			const data = await res.json();
			if (data.ok) {
				const icon = data.type === 'in' ? '✅' : '👋';
				show('success', icon + ' ' + data.message + ' <span class="badge bg-' + (data.status === 'late' ? 'warning' : 'success') + '">' + data.status + '</span>');
				setTimeout(() => window.location.reload(), 1500);
			} else {
				show('danger', data.message || 'Could not record. Please try again.');
				btn.disabled = false;
				btn.innerHTML = original;
				busy = false;
			}
		} catch (e) {
			show('danger', 'Network error, please try again.');
			btn.disabled = false;
			btn.innerHTML = original;
			busy = false;
		}
	});
})();
</script>
@endpush
