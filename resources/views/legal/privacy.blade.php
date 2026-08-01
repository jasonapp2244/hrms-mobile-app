@extends('legal.layout')
@section('title', 'Privacy policy')

@section('content')
	<h1>Privacy policy</h1>
	<p class="lede">
		How the {{ config('app.name') }} app and website handle information about you.
	</p>
	<p class="updated">Last updated {{ now()->format('j F Y') }}</p>

	<div class="note">
		<strong>Who holds your information.</strong>
		{{ $company?->name ?? 'Your employer' }} decides what is collected here and
		why. They are the data controller; this app is the tool they use. Questions
		about your own record go to your HR department first.
	</div>

	<h2>What is collected</h2>

	<h3>Given to us by your employer</h3>
	<p>
		Your account is created by HR, not by you. They provide:
	</p>
	<ul>
		<li>Your name and work email address</li>
		<li>Your employee number, job title, department and office</li>
		<li>Whether you work in an office, remotely, or both</li>
		<li>Who your line manager is</li>
		<li>Your working pattern and shifts</li>
	</ul>

	<h3>Recorded when you use the app</h3>
	<div class="scroll">
		<table>
			<thead>
				<tr><th>What</th><th>When</th><th>Why</th></tr>
			</thead>
			<tbody>
				<tr>
					<td>The time you clock in and out</td>
					<td>Each time you press the button</td>
					<td>It is the record of the hours you worked. The time comes from our server, not your phone.</td>
				</tr>
				<tr>
					<td>Your location</td>
					<td>Only at the moment you clock in or out, and only if you allow it</td>
					<td>Recorded alongside the punch for your employer. <strong>It never stops you clocking in.</strong> Refusing the permission is fine and the button still works.</td>
				</tr>
				<tr>
					<td>Your device's IP address</td>
					<td>Each time you clock in or out</td>
					<td>Kept with the punch as a basic record of where the request came from.</td>
				</tr>
				<tr>
					<td>Leave requests</td>
					<td>When you ask for time off</td>
					<td>The dates, the type of leave, any reason you write, and the decision.</td>
				</tr>
				<tr>
					<td>A notification token</td>
					<td>When you sign in on a phone</td>
					<td>So the app can be sent reminders and decisions. It identifies the installation, not you personally.</td>
				</tr>
			</tbody>
		</table>
	</div>

	<h2>Location, specifically</h2>
	<p>
		The app asks for your location <em>only</em> while you are looking at the
		clock-in screen, and uses it <em>only</em> at the moment you press the
		button. It does not run in the background and does not follow you around.
	</p>
	<p>
		If you refuse the permission, or your phone cannot get a fix, the punch is
		recorded without a location. You are never blocked from clocking in.
	</p>

	<h2>Who can see it</h2>
	<ul>
		<li><strong>You</strong> — your own attendance, leave and schedule.</li>
		<li><strong>Your line manager</strong> — whether their team members are in today, and leave requests waiting on them.</li>
		<li><strong>HR and administrators</strong> at {{ $company?->name ?? 'your employer' }} — attendance and leave across the organisation.</li>
	</ul>
	<p>
		Your information is <strong>not sold</strong>, and is not shared with
		advertisers or data brokers. It is shared with third parties only where
		necessary to run the service — for example the hosting provider that runs
		the server, and Google's notification service if push notifications are
		switched on.
	</p>

	<h2>How long it is kept</h2>
	<p>
		Attendance and leave records are employment records. Your employer decides
		how long to keep them and may be legally required to keep them for a number
		of years after you leave — for tax, payroll or employment law reasons. Ask
		your HR department for the period that applies to you.
	</p>

	<h2>How it is protected</h2>
	<ul>
		<li>Traffic between the app and the server is encrypted.</li>
		<li>The sign-in token on your phone is kept in the device's secure keystore, not in ordinary storage.</li>
		<li>It is excluded from phone backups and device-to-device transfers, so a restored copy cannot sign in as you.</li>
		<li>Attendance records cannot be edited or deleted once written — including by administrators — and every entry records who caused it.</li>
	</ul>

	<h2>Your rights</h2>
	<p>
		Depending on where you live you may have the right to ask for a copy of your
		information, to have mistakes corrected, or to ask that it be deleted. Because
		your employer holds this information, those requests go to them.
		See <a href="{{ route('legal.deletion') }}">deleting your data</a>.
	</p>

	<h2>Children</h2>
	<p>
		This is a workplace tool. It is not intended for, and is not directed at,
		anyone under 16.
	</p>

	<h2>Contact</h2>
	<p>
		Contact your HR department at {{ $company?->name ?? 'your employer' }}
		@if($company?->email) — <a href="mailto:{{ $company->email }}">{{ $company->email }}</a>@endif.
	</p>

	<div class="note">
		<strong>For whoever publishes this app.</strong> This page is generated from
		what the software actually does, and is accurate on that count. It is not
		legal advice. Have it reviewed against the law that applies to you —
		particularly UK/EU GDPR, which requires you to name your lawful basis for
		processing and your retention periods — and fill in your organisation's
		contact details before you publish.
	</div>
@endsection
