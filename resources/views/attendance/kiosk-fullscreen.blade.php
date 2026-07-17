<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
	<title>{{ $office->name }} — Attendance Kiosk</title>
	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('assets/img/favicon.png') }}">
	<style>
		*{margin:0;padding:0;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
		html,body{height:100%}
		body{
			background:radial-gradient(circle at 50% 0%,#1e3a8a 0%,#0f172a 55%,#0b1120 100%);
			color:#e2e8f0;display:flex;flex-direction:column;overflow:hidden;
			transition:cursor .3s;
		}
		body.idle{cursor:none}
		.topbar{display:flex;align-items:center;justify-content:space-between;padding:26px 40px}
		.brand{display:flex;align-items:center;gap:14px}
		.brand .dot{width:14px;height:14px;border-radius:50%;background:#22c55e;box-shadow:0 0 12px #22c55e;animation:pulse 2s infinite}
		@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
		.brand h1{font-size:22px;font-weight:700;letter-spacing:.3px}
		.brand span{color:#94a3b8;font-size:13px;display:block;font-weight:400}
		#clock{font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;color:#cbd5e1}
		#clock small{display:block;text-align:right;font-size:13px;color:#64748b;font-weight:400}

		.stage{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:26px}
		.office-name{font-size:34px;font-weight:800;letter-spacing:.5px}
		.subtitle{color:#94a3b8;font-size:17px;margin-top:-16px}
		.qr-card{background:#fff;border-radius:28px;padding:26px;box-shadow:0 30px 80px rgba(0,0,0,.5);position:relative}
		.qr-card svg{display:block;width:min(46vh,420px);height:min(46vh,420px)}
		#qr-holder{display:flex;align-items:center;justify-content:center;min-width:280px;min-height:280px}
		.spinner{width:56px;height:56px;border:5px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin 1s linear infinite}
		@keyframes spin{to{transform:rotate(360deg)}}

		.countdown-wrap{width:min(46vh,420px)}
		.countdown-meta{display:flex;justify-content:space-between;font-size:14px;color:#94a3b8;margin-bottom:8px}
		.bar{height:8px;background:rgba(255,255,255,.12);border-radius:99px;overflow:hidden}
		#bar-fill{height:100%;width:100%;background:linear-gradient(90deg,#22c55e,#0ea5e9);transition:width 1s linear}

		.footer{text-align:center;padding:22px;color:#64748b;font-size:14px}
		.footer .lock{color:#38bdf8}

		#fs-btn{position:fixed;bottom:20px;right:20px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
			color:#e2e8f0;padding:12px 18px;border-radius:12px;font-size:14px;cursor:pointer;backdrop-filter:blur(6px);transition:opacity .3s}
		#fs-btn:hover{background:rgba(255,255,255,.2)}
		body.idle #fs-btn{opacity:0;pointer-events:none}
		.err{color:#f87171;font-size:18px}
	</style>
</head>
<body>
	<div class="topbar">
		<div class="brand">
			<span class="dot"></span>
			<div><h1>{{ config('app.name') }}<span>Attendance Kiosk</span></h1></div>
		</div>
		<div id="clock">--:--:--<small id="date"></small></div>
	</div>

	<div class="stage">
		<div class="office-name">{{ $office->name }}</div>
		<div class="subtitle">Scan the code below to clock <b>in</b> or <b>out</b></div>

		<div class="qr-card">
			<div id="qr-holder"><div class="spinner"></div></div>
		</div>

		<div class="countdown-wrap">
			<div class="countdown-meta">
				<span>🔄 Code rotates automatically</span>
				<span><span id="countdown">--</span>s</span>
			</div>
			<div class="bar"><div id="bar-fill"></div></div>
		</div>
	</div>

	<div class="footer">
		<span class="lock">🔒</span> Secure rotating code — a screenshot expires within seconds and cannot be reused off-site.
	</div>

	<button id="fs-btn" onclick="toggleFs()">⛶ Full Screen</button>

	<script>
	(function () {
		const qrUrl = @json($qrUrl);
		const holder = document.getElementById('qr-holder');
		const countdownEl = document.getElementById('countdown');
		const bar = document.getElementById('bar-fill');
		let windowSecs = {{ \App\Services\QrTokenService::WINDOW_SECONDS }};
		let remaining = windowSecs;

		async function refresh() {
			try {
				const res = await fetch(qrUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
				if (!res.ok) throw new Error('http ' + res.status);
				const data = await res.json();
				holder.innerHTML = data.svg;
				windowSecs = data.window;
				remaining = data.expires_in;
			} catch (e) {
				holder.innerHTML = '<div class="err">Connection error — retrying…</div>';
				remaining = 3;
			}
		}
		function tick() {
			remaining--;
			if (remaining <= 0) refresh();
			countdownEl.textContent = Math.max(0, remaining);
			bar.style.width = Math.max(0, (remaining / windowSecs) * 100) + '%';
		}

		// Live clock
		function clock() {
			const d = new Date();
			const pad = n => String(n).padStart(2, '0');
			document.getElementById('clock').firstChild.textContent =
				pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
			document.getElementById('date').textContent =
				d.toLocaleDateString(undefined, { weekday:'long', day:'numeric', month:'short', year:'numeric' });
		}

		refresh();
		clock();
		setInterval(tick, 1000);
		setInterval(clock, 1000);

		// Auto-hide cursor / controls after inactivity
		let idleTimer;
		function activity() {
			document.body.classList.remove('idle');
			clearTimeout(idleTimer);
			idleTimer = setTimeout(() => document.body.classList.add('idle'), 4000);
		}
		['mousemove','touchstart','keydown'].forEach(e => document.addEventListener(e, activity));
		activity();
	})();

	function toggleFs() {
		if (!document.fullscreenElement) {
			document.documentElement.requestFullscreen?.();
		} else {
			document.exitFullscreen?.();
		}
	}
	document.addEventListener('fullscreenchange', () => {
		document.getElementById('fs-btn').textContent =
			document.fullscreenElement ? '⛶ Exit Full Screen' : '⛶ Full Screen';
	});
	</script>
</body>
</html>
