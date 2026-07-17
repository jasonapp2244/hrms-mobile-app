@extends('layouts.app')
@section('title', 'QR Scanner')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance Scanner</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">Scanner</li>
			</ol>
		</nav>
	</div>
	<a href="{{ route('attendance.kiosk') }}" class="btn btn-outline-primary mb-2"><i class="ti ti-qrcode me-1"></i>Open Kiosk</a>
</div>

<div class="row justify-content-center">
	<div class="col-lg-6 col-md-8">
		<div class="card">
			<div class="card-header"><h5 class="mb-0">Scan Office QR to Clock In / Out</h5></div>
			<div class="card-body">
				<div class="mb-3">
					<label class="form-label">Who are you?</label>
					<select id="employee" class="form-select">
						<option value="">— Select your name —</option>
						@foreach($employees as $e)
							<option value="{{ $e->id }}">{{ $e->employee_code }} — {{ $e->first_name }} {{ $e->last_name }}</option>
						@endforeach
					</select>
				</div>

				<div id="reader" style="width:100%;border-radius:12px;overflow:hidden"></div>

				<div class="d-flex gap-2 mt-3">
					<button id="start-btn" class="btn btn-primary flex-fill"><i class="ti ti-camera me-1"></i>Start Camera</button>
					<button id="stop-btn" class="btn btn-outline-secondary flex-fill" style="display:none"><i class="ti ti-camera-off me-1"></i>Stop</button>
				</div>

				<div id="result" class="mt-3"></div>
			</div>
		</div>
	</div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/html5-qrcode.min.js') }}"></script>
<script>
(function () {
	const scanUrl = "{{ route('attendance.scan') }}";
	const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
	const resultEl = document.getElementById('result');
	const employeeEl = document.getElementById('employee');
	const startBtn = document.getElementById('start-btn');
	const stopBtn = document.getElementById('stop-btn');
	let html5Qr = null;
	let busy = false;
	let lastPayload = null;
	let lastTime = 0;

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
		// debounce identical rapid scans
		if (busy || (payload === lastPayload && now - lastTime < 4000)) return;
		lastPayload = payload; lastTime = now;

		if (!employeeEl.value) { show('warning', 'Please select your name first.'); return; }
		busy = true;
		const geo = await getGeo();

		try {
			const res = await fetch(scanUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
				body: JSON.stringify({ employee_id: employeeEl.value, payload: payload, latitude: geo.latitude, longitude: geo.longitude })
			});
			const data = await res.json();
			if (data.ok) {
				const icon = data.type === 'in' ? '➡️' : '⬅️';
				show('success', icon + ' <b>' + data.employee + '</b> clocked <b>' + data.type.toUpperCase() +
					'</b> at ' + data.time + ' <span class="badge bg-' + (data.status === 'late' ? 'warning' : 'success') + '">' + data.status + '</span>');
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
		html5Qr.start({ facingMode: 'environment' }, { fps: 10, qrbox: 250 }, onScan)
			.then(() => { startBtn.style.display = 'none'; stopBtn.style.display = 'block'; })
			.catch(err => show('danger', 'Cannot access camera: ' + err));
	});

	stopBtn.addEventListener('click', function () {
		if (html5Qr) html5Qr.stop().then(() => {
			startBtn.style.display = 'block'; stopBtn.style.display = 'none';
		});
	});
})();
</script>
@endpush
