<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title>{{ $display->office?->name }} — Check-in | {{ config('app.name') }}</title>
	<link rel="icon" type="image/png" href="{{ asset('assets/img/favicon.png') }}">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap">
	<link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
	{{-- A4.21. The office screen. Same palette, type and icons as the admin
	     panel (style.css: navy #033C93, Roboto, Tabler), so the tablet on the
	     wall reads as part of the product rather than a separate gadget. The
	     dashboard stylesheet itself is not loaded: this page has no sidebar,
	     no forms and no session, and must stay light on a cheap tablet. --}}
	<style>
		:root{
			--navy:#033C93; --navy-deep:#022a68; --navy-soft:#EAF0FA;
			--gold:#F5B400; --green:#03C95A; --green-soft:#E6FAF0;
			--ink:#111827; --muted:#6B7280; --line:#E5E7EB; --bg:#F8F9FA; --card:#FFFFFF;
		}
		*{margin:0;padding:0;box-sizing:border-box}
		html,body{height:100%}
		body{font-family:"Roboto",sans-serif;background:var(--bg);color:var(--ink);display:flex;flex-direction:column;overflow:hidden;-webkit-font-smoothing:antialiased}
		body.idle{cursor:none}

		/* Top bar — the admin header's white bar with the logo on the left. */
		.topbar{background:var(--card);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:14px 32px;gap:16px;box-shadow:0 1px 3px rgba(16,24,40,.04)}
		.topbar img{height:40px;width:auto;display:block}
		.status{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:#027A48;background:var(--green-soft);border-radius:999px;padding:6px 12px}
		.status .dot{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 0 0 rgba(3,201,90,.6);animation:ping 2s infinite}
		.status.offline{color:#B54708;background:#FEF6E7}
		.status.offline .dot{background:#F79009;animation:none}
		@keyframes ping{0%{box-shadow:0 0 0 0 rgba(3,201,90,.5)}70%{box-shadow:0 0 0 8px rgba(3,201,90,0)}100%{box-shadow:0 0 0 0 rgba(3,201,90,0)}}
		.clock{text-align:right;line-height:1.15}
		.clock .time{font-size:28px;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums;letter-spacing:.5px}
		.clock .date{font-size:13px;color:var(--muted)}

		/* Page heading band, echoing the admin's page-breadcrumb block. */
		.heading{padding:22px 32px 0;display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap}
		.heading h1{font-size:28px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:10px}
		.heading h1 i{color:var(--navy);font-size:30px}
		.heading p{color:var(--muted);font-size:14px;margin-top:4px}
		.chip{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--navy);background:var(--navy-soft);border-radius:8px;padding:6px 10px}

		.main{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:24px;padding:20px 32px 24px}
		.card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 4px 16px rgba(16,24,40,.06)}

		/* The code. */
		.qr-card{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px;position:relative;min-height:0}
		.qr-frame{position:relative;padding:18px;border-radius:20px;background:#fff;border:2px solid var(--navy-soft)}
		.qr-frame::before,.qr-frame::after,.qr-frame .c1,.qr-frame .c2{content:"";position:absolute;width:34px;height:34px;border:4px solid var(--navy)}
		.qr-frame::before{top:-2px;left:-2px;border-right:0;border-bottom:0;border-top-left-radius:20px}
		.qr-frame::after{top:-2px;right:-2px;border-left:0;border-bottom:0;border-top-right-radius:20px}
		.qr-frame .c1{bottom:-2px;left:-2px;border-right:0;border-top:0;border-bottom-left-radius:20px}
		.qr-frame .c2{bottom:-2px;right:-2px;border-left:0;border-top:0;border-bottom-right-radius:20px}
		#qr-holder{width:min(46vh,34vw,440px);height:min(46vh,34vw,440px);display:flex;align-items:center;justify-content:center;transition:opacity .25s}
		#qr-holder svg{display:block;width:100%;height:100%}
		#qr-holder.swap{opacity:.15}
		.spinner{width:52px;height:52px;border:5px solid var(--navy-soft);border-top-color:var(--navy);border-radius:50%;animation:spin 1s linear infinite}
		@keyframes spin{to{transform:rotate(360deg)}}
		.timer{margin-top:22px;display:flex;align-items:center;gap:14px;width:min(46vh,34vw,440px)}
		.ring{width:46px;height:46px;flex:none;position:relative}
		.ring svg{transform:rotate(-90deg)}
		.ring circle{fill:none;stroke-width:5}
		.ring .track{stroke:var(--navy-soft)}
		.ring .bar{stroke:var(--navy);stroke-linecap:round;transition:stroke-dashoffset 1s linear}
		.ring span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}
		.timer .txt{font-size:14px;color:var(--muted);line-height:1.35}
		.timer .txt b{color:var(--ink);font-weight:500}

		/* Right column: how, and who just did. */
		.side{display:flex;flex-direction:column;gap:24px;min-height:0}
		.steps{padding:24px 26px}
		.card-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:16px;display:flex;align-items:center;gap:8px}
		.card-title i{color:var(--navy);font-size:20px}
		.step{display:flex;gap:14px;align-items:flex-start;padding:12px 0}
		.step + .step{border-top:1px dashed var(--line)}
		.step .num{flex:none;width:40px;height:40px;border-radius:12px;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px}
		.step.accent .num{background:var(--gold);color:var(--navy-deep)}
		.step h3{font-size:16px;font-weight:500;color:var(--ink)}
		.step p{font-size:14px;color:var(--muted);margin-top:2px}
		.recent{padding:24px 26px;flex:1;min-height:0;display:flex;flex-direction:column}
		.recent ul{list-style:none;overflow:hidden;flex:1}
		.recent li{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);animation:slide .35s ease}
		.recent li:last-child{border-bottom:0}
		@keyframes slide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
		.avatar{flex:none;width:38px;height:38px;border-radius:50%;background:var(--navy-soft);color:var(--navy);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px}
		.recent .who{flex:1;min-width:0}
		.recent .who b{display:block;font-size:15px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		.recent .who span{font-size:13px;color:var(--muted)}
		.badge{font-size:12px;font-weight:500;border-radius:6px;padding:4px 8px;white-space:nowrap}
		.badge.in{background:var(--green-soft);color:#027A48}
		.badge.out{background:var(--navy-soft);color:var(--navy)}
		.empty{color:var(--muted);font-size:14px;display:flex;align-items:center;gap:8px;padding:8px 0}

		.footer{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px 16px;color:var(--muted);font-size:13px}
		.footer i{color:var(--navy);font-size:16px}

		/* The acknowledgement — large enough to read from the door. */
		#toast{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%) scale(.9);opacity:0;pointer-events:none;background:var(--card);border-radius:20px;box-shadow:0 30px 80px rgba(16,24,40,.25);padding:34px 44px;text-align:center;min-width:min(420px,90vw);transition:opacity .25s,transform .25s;border-top:6px solid var(--green)}
		#toast.show{opacity:1;transform:translate(-50%,-50%) scale(1)}
		#toast.out{border-top-color:var(--navy)}
		#toast .icon{width:72px;height:72px;margin:0 auto 14px;border-radius:50%;background:var(--green-soft);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:40px}
		#toast.out .icon{background:var(--navy-soft);color:var(--navy)}
		#toast h2{font-size:26px;font-weight:700;color:var(--ink)}
		#toast p{font-size:17px;color:var(--muted);margin-top:6px}
		#veil{position:fixed;inset:0;background:rgba(17,24,39,.25);opacity:0;pointer-events:none;transition:opacity .25s}
		#veil.show{opacity:1}

		.stopped{margin:auto;text-align:center;max-width:520px;padding:40px}
		.stopped i{font-size:56px;color:#D92D20}
		.stopped h2{font-size:24px;margin:12px 0 6px}
		.stopped p{color:var(--muted)}

		#fs-btn{position:fixed;bottom:18px;right:18px;background:var(--card);border:1px solid var(--line);color:var(--navy);padding:10px 14px;border-radius:10px;font:500 14px "Roboto",sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;box-shadow:0 4px 12px rgba(16,24,40,.08);transition:opacity .3s}
		body.idle #fs-btn{opacity:0;pointer-events:none}

		/* Portrait tablet or phone: one column, code first. */
		@media (max-aspect-ratio: 1/1), (max-width: 900px){
			body{overflow:auto}
			.main{grid-template-columns:1fr}
			#qr-holder,.timer{width:min(70vw,420px)}
			#qr-holder{height:min(70vw,420px)}
			.recent{display:none}
			.topbar{padding:12px 16px}
			.heading,.main{padding-left:16px;padding-right:16px}
			.topbar img{height:32px}
			.clock .time{font-size:22px}
			.status{display:none}
		}
	</style>
</head>
<body>
	<header class="topbar">
		<img src="{{ asset('assets/img/logo.png') }}" width="181" height="40" alt="{{ config('app.name') }}">
		<span class="status" id="status"><span class="dot"></span><span id="status-text">Live</span></span>
		<div class="clock"><div class="time" id="time">--:--</div><div class="date" id="date"></div></div>
	</header>

	<section class="heading">
		<div>
			<h1><i class="ti ti-building"></i>{{ $display->office?->name }}</h1>
			<p>Scan with the {{ config('app.name') }} app on your phone to check in or out.</p>
		</div>
		<span class="chip"><i class="ti ti-device-desktop"></i>{{ $display->name }}</span>
	</section>

	<main class="main" id="stage">
		<div class="card qr-card">
			<div class="qr-frame"><span class="c1"></span><span class="c2"></span>
				<div id="qr-holder"><div class="spinner"></div></div>
			</div>
			<div class="timer">
				<div class="ring">
					<svg width="46" height="46" viewBox="0 0 46 46"><circle class="track" cx="23" cy="23" r="19"/><circle class="bar" id="ring-bar" cx="23" cy="23" r="19" stroke-dasharray="119.4" stroke-dashoffset="0"/></svg>
					<span id="countdown">--</span>
				</div>
				<div class="txt"><b>A new code appears after every scan</b><br>and every {{ \App\Services\QrAttendanceService::TOKEN_SECONDS }} seconds on its own.</div>
			</div>
		</div>

		<div class="side">
			<div class="card steps">
				<div class="card-title"><i class="ti ti-list-numbers"></i>How to check in</div>
				<div class="step"><div class="num"><i class="ti ti-device-mobile"></i></div><div><h3>Open the {{ config('app.name') }} app</h3><p>On your own phone, signed in to your account.</p></div></div>
				<div class="step accent"><div class="num"><i class="ti ti-scan"></i></div><div><h3>Tap “Scan to check in”</h3><p>The same button checks you out when you leave.</p></div></div>
				<div class="step"><div class="num"><i class="ti ti-qrcode"></i></div><div><h3>Point it at this code</h3><p>You will see your name here when it is recorded.</p></div></div>
			</div>

			<div class="card recent">
				<div class="card-title"><i class="ti ti-history"></i>Just now</div>
				<ul id="recent"><li class="empty" id="recent-empty"><i class="ti ti-clock"></i>Scans from this screen appear here.</li></ul>
			</div>
		</div>
	</main>

	<footer class="footer"><i class="ti ti-shield-check"></i>Each code works once. A photo of this screen will not check anybody in.</footer>

	<div id="veil"></div>
	<div id="toast" role="status" aria-live="polite">
		<div class="icon"><i class="ti ti-check" id="toast-icon"></i></div>
		<h2 id="toast-name"></h2>
		<p id="toast-text"></p>
	</div>

	<button id="fs-btn" type="button"><i class="ti ti-maximize"></i><span>Full screen</span></button>

	<script>
	(function () {
		const currentUrl = @json($currentUrl);
		// The company's clock, not the tablet's: attendance is judged there.
		const zone = @json($display->company?->tz() ?? config('app.timezone'));
		const lifetime = {{ \App\Services\QrAttendanceService::TOKEN_SECONDS }};
		const circumference = 119.4;

		const $ = id => document.getElementById(id);
		const holder = $('qr-holder'), ringBar = $('ring-bar'), countdownEl = $('countdown');
		const statusEl = $('status'), statusText = $('status-text');
		const toast = $('toast'), veil = $('veil'), recent = $('recent');

		// How long one request may take before it is given up on. Without a
		// limit a request that never answered — the PC slept mid-request, the
		// network dropped — held `busy` for ever, and the screen sat on one code
		// long after it had expired, with nothing to say so.
		const requestTimeoutMs = 5000;

		let showing = null, expiresAt = 0, busy = false, stopped = false, lastScan = '', toastTimer;

		function stop() {
			stopped = true;
			$('stage').innerHTML = '<div class="stopped"><i class="ti ti-plug-connected-x"></i>'
				+ '<h2>This screen has been switched off</h2>'
				+ '<p>Ask HR for a new link from Attendance → QR Screens.</p></div>';
		}

		function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
		function initials(name) { return name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join(''); }

		function announce(scan) {
			const key = scan.name + '|' + scan.type + '|' + scan.time;
			if (key === lastScan) return;
			lastScan = key;

			const out = scan.type === 'out';
			const verb = out ? 'Checked out' : 'Checked in';

			$('toast-name').textContent = scan.name;
			$('toast-text').textContent = verb + ' at ' + scan.time;
			$('toast-icon').className = 'ti ' + (out ? 'ti-logout' : 'ti-check');
			toast.className = 'show' + (out ? ' out' : '');
			veil.className = 'show';
			clearTimeout(toastTimer);
			toastTimer = setTimeout(() => { toast.className = out ? 'out' : ''; veil.className = ''; }, 3200);

			const empty = $('recent-empty');
			if (empty) empty.remove();
			const li = document.createElement('li');
			li.innerHTML = '<div class="avatar">' + esc(initials(scan.name)) + '</div>'
				+ '<div class="who"><b>' + esc(scan.name) + '</b><span>' + esc(scan.time) + '</span></div>'
				+ '<span class="badge ' + (out ? 'out' : 'in') + '">' + verb + '</span>';
			recent.prepend(li);
			while (recent.children.length > 6) recent.lastElementChild.remove();
		}

		function online(ok) {
			statusEl.classList.toggle('offline', !ok);
			statusText.textContent = ok ? 'Live' : 'Reconnecting…';
		}

		async function poll() {
			if (busy || stopped) return;
			busy = true;
			const abort = new AbortController();
			const timer = setTimeout(() => abort.abort(), requestTimeoutMs);
			try {
				// A header, not a query parameter: the link is signed, and any
				// parameter added to it would fail the signature.
				const headers = { 'Accept': 'application/json' };
				if (showing) headers['X-Showing'] = String(showing);
				const res = await fetch(currentUrl, { headers, cache: 'no-store', signal: abort.signal });
				if (res.status === 410 || res.status === 403) { stop(); return; }
				if (!res.ok) throw new Error('http ' + res.status);
				const data = await res.json();
				if (data.changed) {
					holder.classList.add('swap');
					setTimeout(() => {
						holder.innerHTML = data.svg || '<div class="empty">Could not draw the code.</div>';
						holder.classList.remove('swap');
					}, showing ? 180 : 0);
					showing = data.token_id;
				}
				// A moment in time rather than a count of ticks: a browser slows
				// the timers of a tab it is not showing, and a counter would then
				// drift from the server's clock.
				expiresAt = Date.now() + data.expires_in * 1000;
				if (data.last_scan) announce(data.last_scan);
				online(true);
			} catch (e) {
				online(false);
			} finally {
				clearTimeout(timer);
				busy = false;
			}
		}

		function tick() {
			const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
			countdownEl.textContent = showing ? remaining : '--';
			ringBar.style.strokeDashoffset = circumference * (1 - Math.min(1, remaining / lifetime));

			// Never leave a dead code on the wall. A phone would read it and be
			// refused, and the person holding it could not tell why. Forgetting
			// it also makes the next poll ask for a fresh one.
			if (showing && remaining === 0) {
				showing = null;
				holder.innerHTML = '<div class="spinner"></div>';
			}
		}

		// A tab brought back to the front has had its timers slowed; ask at once
		// rather than at whatever moment the next one fires.
		document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

		function clock() {
			const d = new Date();
			$('time').textContent = d.toLocaleTimeString([], { timeZone: zone, hour: '2-digit', minute: '2-digit' });
			$('date').textContent = d.toLocaleDateString([], { timeZone: zone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
		}

		poll(); clock(); tick();
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

		const fs = $('fs-btn');
		fs.addEventListener('click', () => {
			if (!document.fullscreenElement) document.documentElement.requestFullscreen?.();
			else document.exitFullscreen?.();
		});
		document.addEventListener('fullscreenchange', () => {
			fs.querySelector('span').textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
			fs.querySelector('i').className = 'ti ' + (document.fullscreenElement ? 'ti-minimize' : 'ti-maximize');
		});
	})();
	</script>
</body>
</html>
