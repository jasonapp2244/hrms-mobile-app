<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
	<meta name="robots" content="noindex, nofollow">
	<title>{{ $display->office?->name }} — Check-in</title>
	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('assets/img/favicon.png') }}">
	<style>
		*{margin:0;padding:0;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
		html,body{height:100%}
		body{background:radial-gradient(circle at 50% 0%,#1e3a8a 0%,#0f172a 55%,#0b1120 100%);color:#e2e8f0;display:flex;flex-direction:column;overflow:hidden}
		body.idle{cursor:none}
		.topbar{display:flex;align-items:center;justify-content:space-between;padding:22px 36px}
		.brand{display:flex;align-items:center;gap:12px}
		.brand .dot{width:14px;height:14px;border-radius:50%;background:#22c55e;box-shadow:0 0 12px #22c55e;animation:pulse 2s infinite}
		.brand.offline .dot{background:#f59e0b;box-shadow:0 0 12px #f59e0b}
		@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
		.brand h1{font-size:22px;font-weight:700}
		.brand span{color:#94a3b8;font-size:13px;display:block;font-weight:400}
		#clock{font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;color:#cbd5e1;text-align:right}
		#clock small{display:block;font-size:13px;color:#64748b;font-weight:400}
		.stage{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:0 16px}
		.office-name{font-size:34px;font-weight:800;text-align:center}
		.subtitle{color:#94a3b8;font-size:18px;margin-top:-12px;text-align:center}
		.qr-card{background:#fff;border-radius:28px;padding:24px;box-shadow:0 30px 80px rgba(0,0,0,.5);position:relative}
		#qr-holder{display:flex;align-items:center;justify-content:center;width:min(48vh,80vw,440px);height:min(48vh,80vw,440px)}
		#qr-holder svg{display:block;width:100%;height:100%}
		.spinner{width:56px;height:56px;border:5px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin 1s linear infinite}
		@keyframes spin{to{transform:rotate(360deg)}}
		.countdown-wrap{width:min(48vh,80vw,440px)}
		.countdown-meta{display:flex;justify-content:space-between;font-size:14px;color:#94a3b8;margin-bottom:8px}
		.bar{height:8px;background:rgba(255,255,255,.12);border-radius:99px;overflow:hidden}
		#bar-fill{height:100%;width:100%;background:linear-gradient(90deg,#22c55e,#0ea5e9);transition:width 1s linear}
		#banner{position:fixed;left:50%;top:24px;transform:translate(-50%,-160%);background:#16a34a;color:#fff;padding:18px 30px;border-radius:16px;font-size:24px;font-weight:700;box-shadow:0 20px 50px rgba(0,0,0,.4);transition:transform .35s ease;max-width:92vw;text-align:center}
		#banner.show{transform:translate(-50%,0)}
		#banner.out{background:#2563eb}
		.footer{text-align:center;padding:20px;color:#64748b;font-size:14px}
		#notice{color:#fbbf24;font-size:16px;min-height:22px;text-align:center}
		.stopped{font-size:22px;color:#f87171;text-align:center;padding:40px}
		#fs-btn{position:fixed;bottom:20px;right:20px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#e2e8f0;padding:12px 18px;border-radius:12px;font-size:14px;cursor:pointer;transition:opacity .3s}
		body.idle #fs-btn{opacity:0;pointer-events:none}
	</style>
</head>
<body>
	<div class="topbar">
		<div class="brand" id="brand">
			<span class="dot"></span>
			<div><h1>{{ config('app.name') }}<span>{{ $display->name }}</span></h1></div>
		</div>
		<div id="clock">--:--<small id="date"></small></div>
	</div>

	<div class="stage" id="stage">
		<div class="office-name">{{ $display->office?->name }}</div>
		<div class="subtitle">Open the app, tap <b>Scan to check in</b>, and point your phone here</div>
		<div class="qr-card"><div id="qr-holder"><div class="spinner"></div></div></div>
		<div class="countdown-wrap">
			<div class="countdown-meta">
				<span>New code after every scan</span>
				<span><span id="countdown">--</span>s</span>
			</div>
			<div class="bar"><div id="bar-fill"></div></div>
		</div>
		<div id="notice"></div>
	</div>

	<div class="footer">A code works once. A photo of this screen will not check anybody in.</div>
	<div id="banner"></div>
	<button id="fs-btn" type="button">⛶ Full screen</button>

	<script>
	(function () {
		const currentUrl = @json($currentUrl);
		// The company's clock, not the tablet's: attendance is judged there.
		const zone = @json($display->company?->tz() ?? config('app.timezone'));
		const lifetime = {{ \App\Services\QrAttendanceService::TOKEN_SECONDS }};
		const holder = document.getElementById('qr-holder');
		const countdownEl = document.getElementById('countdown');
		const bar = document.getElementById('bar-fill');
		const notice = document.getElementById('notice');
		const brand = document.getElementById('brand');
		const banner = document.getElementById('banner');
		let showing = null, remaining = lifetime, busy = false, stopped = false, lastBanner = '', bannerTimer;

		function stop(message) {
			stopped = true;
			document.getElementById('stage').innerHTML = '<div class="stopped">' + message + '</div>';
		}

		function announce(scan) {
			const key = scan.name + scan.type + scan.time;
			if (key === lastBanner) return;
			lastBanner = key;
			const verb = { in: 'checked in', out: 'checked out' }[scan.type] || scan.type;
			banner.textContent = '✓ ' + scan.name + ' — ' + verb + ' ' + scan.time;
			banner.className = 'show' + (scan.type === 'out' ? ' out' : '');
			clearTimeout(bannerTimer);
			bannerTimer = setTimeout(() => { banner.className = scan.type === 'out' ? 'out' : ''; }, 4000);
		}

		async function poll() {
			if (busy || stopped) return;
			busy = true;
			try {
				// A header, not a query parameter: the link is signed, and any
				// parameter added to it would fail the signature.
				const headers = { 'Accept': 'application/json' };
				if (showing) headers['X-Showing'] = String(showing);
				const res = await fetch(currentUrl, { headers, cache: 'no-store' });
				if (res.status === 410 || res.status === 403) {
					stop('This screen has been switched off. Ask HR for a new link.');
					return;
				}
				if (!res.ok) throw new Error('http ' + res.status);
				const data = await res.json();
				if (data.changed) {
					holder.innerHTML = data.svg || '<div class="stopped">Could not draw the code.</div>';
					showing = data.token_id;
				}
				remaining = data.expires_in;
				if (data.last_scan) announce(data.last_scan);
				notice.textContent = '';
				brand.classList.remove('offline');
			} catch (e) {
				notice.textContent = 'No connection — reconnecting…';
				brand.classList.add('offline');
			} finally {
				busy = false;
			}
		}

		function tick() {
			remaining = Math.max(0, remaining - 1);
			countdownEl.textContent = remaining;
			bar.style.width = Math.min(100, (remaining / lifetime) * 100) + '%';
		}

		function clock() {
			const d = new Date();
			document.getElementById('clock').firstChild.textContent =
				d.toLocaleTimeString([], { timeZone: zone, hour: '2-digit', minute: '2-digit' });
			document.getElementById('date').textContent =
				d.toLocaleDateString([], { timeZone: zone, weekday: 'long', day: 'numeric', month: 'short' });
		}

		poll(); clock();
		setInterval(poll, 1000);
		setInterval(tick, 1000);
		setInterval(clock, 1000);

		let idleTimer;
		function activity() {
			document.body.classList.remove('idle');
			clearTimeout(idleTimer);
			idleTimer = setTimeout(() => document.body.classList.add('idle'), 4000);
		}
		['mousemove', 'touchstart', 'keydown'].forEach(e => document.addEventListener(e, activity));
		activity();

		const fs = document.getElementById('fs-btn');
		fs.addEventListener('click', () => {
			if (!document.fullscreenElement) document.documentElement.requestFullscreen?.();
			else document.exitFullscreen?.();
		});
		document.addEventListener('fullscreenchange', () => {
			fs.textContent = document.fullscreenElement ? '⛶ Exit full screen' : '⛶ Full screen';
		});
	})();
	</script>
</body>
</html>
