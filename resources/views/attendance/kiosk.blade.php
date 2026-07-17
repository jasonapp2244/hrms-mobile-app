@extends('layouts.app')
@section('title', 'QR Kiosk')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance QR Kiosk</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">QR Kiosk</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<form method="GET" class="me-2 mb-2">
			<select name="office" class="form-select" onchange="this.form.submit()">
				@foreach($offices as $o)
					<option value="{{ $o->id }}" {{ $office && $office->id==$o->id ? 'selected' : '' }}>{{ $o->name }}</option>
				@endforeach
			</select>
		</form>
		@if($office)<a href="{{ \Illuminate\Support\Facades\URL::signedRoute('attendance.kiosk.display', ['office' => $office->id]) }}" target="_blank" class="btn btn-primary me-2 mb-2"><i class="ti ti-maximize me-1"></i>Full-Screen Mode</a>@endif
			<a href="{{ route('attendance.scanner') }}" class="btn btn-outline-primary mb-2"><i class="ti ti-camera me-1"></i>Open Scanner</a>
	</div>
</div>

@if(!$office)
	<div class="alert alert-warning">No active office found. Create an office first under <a href="{{ route('offices.index') }}">Company → Offices</a>.</div>
@else
<div class="row justify-content-center">
	<div class="col-lg-6 col-md-8">
		<div class="card text-center">
			<div class="card-header">
				<h4 class="mb-0" id="kiosk-office">{{ $office->name }}</h4>
				<p class="text-muted mb-0">Scan this code with the HRMS scanner to clock in / out</p>
			</div>
			<div class="card-body">
				<div id="qr-holder" class="d-flex justify-content-center align-items-center mx-auto"
					style="width:300px;height:300px;padding:12px;border:2px dashed #dbe6ff;border-radius:16px;">
					<div class="spinner-border text-primary" role="status"></div>
				</div>

				<div class="mt-4">
					<div class="d-flex align-items-center justify-content-between mb-1">
						<small class="text-muted">Code rotates automatically</small>
						<small class="text-muted"><span id="countdown">--</span>s</small>
					</div>
					<div class="progress" style="height:6px">
						<div id="countdown-bar" class="progress-bar bg-primary" style="width:100%"></div>
					</div>
				</div>

				<div class="alert alert-info mt-4 mb-0 text-start fs-13">
					<i class="ti ti-shield-lock me-1"></i>
					<b>Secure:</b> the code is a rotating one-time token — a screenshot expires within seconds, so it can't be reused off-site.
				</div>
			</div>
		</div>
	</div>
</div>
@endif
@endsection

@push('scripts')
@if($office)
<script>
(function () {
	const qrUrl = "{{ route('attendance.qr', $office) }}";
	const holder = document.getElementById('qr-holder');
	const countdownEl = document.getElementById('countdown');
	const bar = document.getElementById('countdown-bar');
	let windowSecs = {{ \App\Services\QrTokenService::WINDOW_SECONDS }};
	let remaining = windowSecs;

	async function refresh() {
		try {
			const res = await fetch(qrUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
			const data = await res.json();
			holder.innerHTML = data.svg;
			const svg = holder.querySelector('svg');
			if (svg) { svg.setAttribute('width', '270'); svg.setAttribute('height', '270'); }
			windowSecs = data.window;
			remaining = data.expires_in;
		} catch (e) {
			holder.innerHTML = '<span class="text-danger">Connection error</span>';
		}
	}

	function tick() {
		remaining--;
		if (remaining <= 0) { refresh(); }
		countdownEl.textContent = Math.max(0, remaining);
		bar.style.width = Math.max(0, (remaining / windowSecs) * 100) + '%';
	}

	refresh();
	setInterval(tick, 1000);
})();
</script>
@endif
@endpush
