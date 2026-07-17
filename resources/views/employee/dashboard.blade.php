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
			<p class="text-muted">Point your camera at the office QR code to record your {{ $nextAction === 'in' ? 'check-in' : 'check-out' }}.</p>

			<div id="reader" style="width:100%;max-width:340px;margin:0 auto;border-radius:12px;overflow:hidden"></div>

			<div class="d-flex gap-2 mt-3 justify-content-center">
				<button id="start-btn" class="btn btn-primary"><i class="ti ti-camera me-1"></i>Start Camera</button>
				<button id="stop-btn" class="btn btn-outline-secondary" style="display:none"><i class="ti ti-camera-off me-1"></i>Stop</button>
			</div>

			<div id="result" class="mt-3"></div>

			<div class="alert alert-info mt-3 mb-0 text-start" style="font-size:12.5px">
				<i class="ti ti-shield-lock me-1"></i>
				The office QR rotates every few seconds and your location is checked, so attendance can only be recorded on-site.
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
<script src="{{ asset('assets/js/html5-qrcode.min.js') }}"></script>
<script>
(function () {
	const scanUrl = "{{ route('employee.scan') }}";
	const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
	const resultEl = document.getElementById('result');
	const startBtn = document.getElementById('start-btn');
	const stopBtn = document.getElementById('stop-btn');
	let html5Qr = null, busy = false, lastPayload = null, lastTime = 0;

	function show(type, msg) {
		resultEl.innerHTML = '<div class="alert alert-' + type + ' mb-0">' + msg + '</div>';
	}

	function getGeo() {
		return new Promise((resolve) => {
			if (!navigator.geolocation) return resolve({});
			navigator.geolocation.getCurrentPosition(
				p => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude }),
				() => resolve({}), { timeout: 3000 }
			);
		});
	}

	async function onScan(payload) {
		const now = Date.now();
		if (busy || (payload === lastPayload && now - lastTime < 4000)) return;
		lastPayload = payload; lastTime = now;
		busy = true;
		const geo = await getGeo();
		try {
			const res = await fetch(scanUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify({ payload: payload, latitude: geo.latitude, longitude: geo.longitude })
			});
			const data = await res.json();
			if (data.ok) {
				const icon = data.type === 'in' ? '✅' : '👋';
				show('success', icon + ' ' + data.message + ' <span class="badge bg-' + (data.status === 'late' ? 'warning' : 'success') + '">' + data.status + '</span>');
				setTimeout(() => window.location.reload(), 1800);
			} else {
				show('danger', data.message || 'Scan rejected.');
			}
		} catch (e) {
			show('danger', 'Network error, please try again.');
		}
		setTimeout(() => { busy = false; }, 1500);
	}

	startBtn.addEventListener('click', function () {
		html5Qr = new Html5Qrcode('reader');
		html5Qr.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, onScan)
			.then(() => { startBtn.style.display = 'none'; stopBtn.style.display = 'inline-block'; })
			.catch(err => show('danger', 'Cannot access camera: ' + err));
	});

	stopBtn.addEventListener('click', function () {
		if (html5Qr) html5Qr.stop().then(() => {
			startBtn.style.display = 'inline-block'; stopBtn.style.display = 'none';
		});
	});
})();
</script>
@endpush
