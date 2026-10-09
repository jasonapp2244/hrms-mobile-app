{{--
    Hand-built rather than a markdown mail because the QR is embedded here, in
    the HTML part only (see EmployeeInvite). Its look copies Laravel's default
    mail theme — header, white panel, sign-off, footer — so it reads as the same
    sender as every other KEMP mail. Tables and inline styles only: that is
    what Outlook and Gmail actually honour.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light">
	<meta name="supported-color-schemes" content="light">
	<title>{{ __('notifications.employee_invite.subject', ['app' => $app]) }}</title>
</head>
<body style="margin:0;padding:0;width:100%;background-color:#ffffff;color:#52525b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:none">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fafafa;width:100%">
		<tr><td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
				<tr><td align="center" style="padding:25px 0">
					<span style="color:#18181b;font-size:19px;font-weight:bold;text-decoration:none">{{ $app }}</span>
				</td></tr>

				<tr><td width="100%" style="background-color:#fafafa;border-bottom:1px solid #fafafa;border-top:1px solid #fafafa">
					<table role="presentation" align="center" width="570" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border:1px solid #e4e4e7;border-radius:4px;width:570px;max-width:100%;margin:0 auto">
						<tr><td style="padding:32px;font-size:16px;line-height:1.5em;color:#52525b">
							<h1 style="color:#18181b;font-size:18px;font-weight:bold;margin:0 0 16px">{{ __('notifications.employee_invite.greeting', ['name' => $name]) }}</h1>
							<p style="margin:0 0 16px">
								{{ __('notifications.employee_invite.intro', ['app' => $app, 'company' => $company ?: $app]) }}
							</p>

							<h2 style="color:#18181b;font-size:16px;font-weight:bold;margin:24px 0 8px">1. {{ __('notifications.employee_invite.step_install') }}</h2>
							<p style="margin:0 0 8px">{{ __('notifications.employee_invite.install_line', ['app' => $app]) }}</p>
							@if($androidUrl || $iosUrl)
								<p style="margin:0 0 8px">
									@if($androidUrl)<a href="{{ $androidUrl }}" style="color:#18181b">Google Play</a>@endif
									@if($androidUrl && $iosUrl) &nbsp;·&nbsp; @endif
									@if($iosUrl)<a href="{{ $iosUrl }}" style="color:#18181b">App Store</a>@endif
								</p>
							@endif

							<h2 style="color:#18181b;font-size:16px;font-weight:bold;margin:24px 0 8px">2. {{ __('notifications.employee_invite.step_scan') }}</h2>
							{{-- $message only exists during a real send; a preview render has no mail to embed into. --}}
							@if($qrPng && isset($message))
								<p style="margin:0 0 12px">{{ __('notifications.employee_invite.scan_line') }}</p>
								<p style="text-align:center;margin:0 0 12px">
									<img src="{{ $message->embedData($qrPng, \App\Notifications\EmployeeInvite::QR_CID, 'image/png') }}"
										alt="{{ __('notifications.employee_invite.qr_alt') }}" width="240" height="240"
										style="display:inline-block;width:240px;height:240px;border:1px solid #e4e4e7">
								</p>
								<p style="font-size:14px;line-height:1.5em;margin:0 0 8px">
									{{ __('notifications.employee_invite.qr_rules', ['days' => $days]) }}
								</p>
							@elseif($qrPng)
								<p style="margin:0 0 8px">{{ __('notifications.employee_invite.text_scan_line') }}</p>
							@else
								<p style="margin:0 0 8px">{{ __('notifications.employee_invite.no_qr', ['email' => $email]) }}</p>
							@endif

							<h2 style="color:#18181b;font-size:16px;font-weight:bold;margin:24px 0 8px">3. {{ __('notifications.employee_invite.step_clock') }}</h2>
							<p style="margin:0 0 16px">{{ __('notifications.employee_invite.clock_line') }}</p>

							<p style="margin:0 0 16px">
								{{ __('notifications.employee_invite.account_line', ['email' => $email]) }}
								<a href="{{ $resetUrl }}" style="color:#18181b">{{ __('notifications.employee_invite.password_link') }}</a>
							</p>

							<p style="margin:0">{{ __('Regards,') }}<br>{{ $app }}</p>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e4e4e7;margin-top:25px;padding-top:25px">
								<tr><td style="font-size:14px;line-height:1.5em">{{ __('notifications.employee_invite.ignore') }}</td></tr>
							</table>
						</td></tr>
					</table>
				</td></tr>

				<tr><td align="center" style="padding:32px;color:#a1a1aa;font-size:12px;text-align:center">
					© {{ date('Y') }} {{ $app }}. {{ __('All rights reserved.') }}
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
