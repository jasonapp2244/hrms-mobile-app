<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{{ __('notifications.employee_invite.subject', ['app' => $app]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1e293b">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px">
		<tr><td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px">
				<tr><td>
					<h1 style="font-size:22px;margin:0 0 16px">{{ __('notifications.employee_invite.greeting', ['name' => $name]) }}</h1>
					<p style="font-size:15px;line-height:1.6;margin:0 0 16px">
						{{ __('notifications.employee_invite.intro', ['app' => $app, 'company' => $company ?: $app]) }}
					</p>

					<h2 style="font-size:17px;margin:24px 0 8px">{{ __('notifications.employee_invite.step_install') }}</h2>
					<p style="font-size:15px;line-height:1.6;margin:0 0 8px">{{ __('notifications.employee_invite.install_line', ['app' => $app]) }}</p>
					@if($androidUrl || $iosUrl)
						<p style="font-size:15px;margin:0 0 8px">
							@if($androidUrl)<a href="{{ $androidUrl }}" style="color:#2563eb">Google Play</a>@endif
							@if($androidUrl && $iosUrl) &nbsp;·&nbsp; @endif
							@if($iosUrl)<a href="{{ $iosUrl }}" style="color:#2563eb">App Store</a>@endif
						</p>
					@endif

					<h2 style="font-size:17px;margin:24px 0 8px">{{ __('notifications.employee_invite.step_scan') }}</h2>
					@if($qrPng)
						<p style="font-size:15px;line-height:1.6;margin:0 0 12px">{{ __('notifications.employee_invite.scan_line') }}</p>
						<p style="text-align:center;margin:0 0 12px">
							<img src="{{ $message->embedData($qrPng, \App\Notifications\EmployeeInvite::QR_CID, 'image/png') }}"
								alt="{{ __('notifications.employee_invite.qr_alt') }}" width="240" height="240"
								style="display:inline-block;width:240px;height:240px;border:1px solid #e2e8f0;border-radius:8px">
						</p>
						<p style="font-size:13px;color:#64748b;line-height:1.5;margin:0 0 8px">
							{{ __('notifications.employee_invite.qr_rules', ['days' => $days]) }}
						</p>
					@else
						<p style="font-size:15px;line-height:1.6;margin:0 0 8px">{{ __('notifications.employee_invite.no_qr', ['email' => $email]) }}</p>
					@endif

					<h2 style="font-size:17px;margin:24px 0 8px">{{ __('notifications.employee_invite.step_clock') }}</h2>
					<p style="font-size:15px;line-height:1.6;margin:0 0 8px">{{ __('notifications.employee_invite.clock_line') }}</p>

					<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">
					<p style="font-size:14px;line-height:1.6;margin:0 0 8px">
						{{ __('notifications.employee_invite.account_line', ['email' => $email]) }}
						<a href="{{ $resetUrl }}" style="color:#2563eb">{{ __('notifications.employee_invite.password_link') }}</a>
					</p>
					<p style="font-size:13px;color:#64748b;line-height:1.5;margin:0">{{ __('notifications.employee_invite.ignore') }}</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
