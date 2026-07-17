<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login | {{ config('app.name') }}</title>
	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('assets/img/favicon.png') }}">
	<link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
	<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
	<style>
		body{background:#f8f9fb}
		.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
		.auth-card{max-width:420px;width:100%;background:#fff;border-radius:16px;
			box-shadow:0 10px 40px rgba(20,30,60,.08);padding:38px 34px}
		.auth-brand{text-align:center;margin-bottom:8px}
		.auth-brand .badge-icon{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#0ea5e9);
			display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:26px}
		.demo-hint{background:#eff6ff;border:1px solid #dbeafe;border-radius:10px;padding:10px 12px;font-size:12.5px;color:#1e40af}
	</style>
</head>
<body>
	<div class="auth-wrap">
		<div class="auth-card">
			<div class="auth-brand mb-4">
				<span class="badge-icon mb-3"><i class="ti ti-clock-check"></i></span>
				<h3 class="mb-1">{{ config('app.name') }}</h3>
				<p class="text-muted mb-0">Admin Dashboard — Sign in to continue</p>
			</div>

			@if ($errors->any())
				<div class="alert alert-danger py-2">{{ $errors->first() }}</div>
			@endif

			<form method="POST" action="{{ route('login') }}">
				@csrf
				<div class="mb-3">
					<label class="form-label">Email Address</label>
					<div class="input-group input-group-flat">
						<span class="input-group-text"><i class="ti ti-mail"></i></span>
						<input type="email" name="email" class="form-control" value="{{ old('email', 'admin@hrms.test') }}" placeholder="you@company.com" required autofocus>
					</div>
				</div>
				<div class="mb-3">
					<label class="form-label">Password</label>
					<div class="input-group input-group-flat" id="pw-group">
						<span class="input-group-text"><i class="ti ti-lock"></i></span>
						<input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
						<span class="input-group-text toggle-pw" style="cursor:pointer"><i class="ti ti-eye" id="pw-icon"></i></span>
					</div>
				</div>
				<div class="d-flex align-items-center justify-content-between mb-3">
					<label class="form-check">
						<input type="checkbox" name="remember" class="form-check-input">
						<span class="form-check-label">Remember me</span>
					</label>
				</div>
				<button type="submit" class="btn btn-primary w-100 mb-3">Sign In</button>
			</form>

			<div class="demo-hint">
				<b>Admin:</b> admin@hrms.test &nbsp;/&nbsp; password<br>
				<b>HR:</b> hr@hrms.test &nbsp;/&nbsp; password<br>
				<span class="text-muted">Admin &amp; HR can sign in. Employee access is via the mobile app phase.</span>
			</div>
		</div>
	</div>

	<script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
	<script>
		document.querySelector('.toggle-pw').addEventListener('click', function () {
			var pw = document.getElementById('password');
			var icon = document.getElementById('pw-icon');
			if (pw.type === 'password') { pw.type = 'text'; icon.className = 'ti ti-eye-off'; }
			else { pw.type = 'password'; icon.className = 'ti ti-eye'; }
		});
	</script>
</body>
</html>
