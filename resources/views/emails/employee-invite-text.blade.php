{{-- Plain text, so nothing here is escaped: {{ }} would turn O'Brien into O&#039;Brien. --}}
{!! __('notifications.employee_invite.greeting', ['name' => $name]) !!}

{!! __('notifications.employee_invite.intro', ['app' => $app, 'company' => $company ?: $app]) !!}

1. {!! __('notifications.employee_invite.step_install') !!}
{!! __('notifications.employee_invite.install_line', ['app' => $app]) !!}
@if($androidUrl)
Google Play: {!! $androidUrl !!}
@endif
@if($iosUrl)
App Store: {!! $iosUrl !!}
@endif

2. {!! __('notifications.employee_invite.step_scan') !!}
{!! __('notifications.employee_invite.text_scan_line') !!}

3. {!! __('notifications.employee_invite.step_clock') !!}
{!! __('notifications.employee_invite.clock_line') !!}

{!! __('notifications.employee_invite.account_line', ['email' => $email]) !!} {!! $resetUrl !!}

{!! __('notifications.employee_invite.ignore') !!}

{!! __('Regards,') !!}
{!! $app !!}
