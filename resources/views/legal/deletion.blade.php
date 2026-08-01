@extends('legal.layout')
@section('title', 'Deleting your data')

@section('content')
	<h1>Deleting your account and data</h1>
	<p class="lede">
		How to ask for your account to be removed, and what happens to the records
		attached to it.
	</p>
	<p class="updated">Last updated {{ now()->format('j F Y') }}</p>

	<div class="note">
		<strong>You cannot delete this account from inside the app, and that is
		deliberate.</strong>
		Your employer created it as part of your employment, not you as a personal
		sign-up. Letting somebody delete their own attendance record would remove
		the evidence of hours they are owed — so the request goes through HR, who
		can check what has to be kept before anything is removed.
	</div>

	<h2>How to ask</h2>
	<ol>
		<li>
			Contact the HR department at
			{{ $company?->name ?? 'your employer' }}@if($company?->email) —
			<a href="mailto:{{ $company->email }}?subject=Account%20deletion%20request">{{ $company->email }}</a>@endif.
		</li>
		<li>Tell them your name and employee number, and that you want your app account and personal data removed.</li>
		<li>They will confirm what can be deleted and what has to be kept, and why.</li>
	</ol>
	<p>
		If you no longer work there and have no HR contact, write to the
		organisation directly. They remain responsible for your record.
	</p>

	<h2>What is deleted</h2>
	<ul>
		<li>Your sign-in account, so it can no longer be used</li>
		<li>Your saved sessions on every device</li>
		<li>Any notification tokens for your phones or tablets</li>
		<li>Your contact details, beyond what has to stay on the employment record</li>
	</ul>

	<h2>What is usually kept, and why</h2>
	<p>
		Attendance and leave records are employment records rather than app data.
		Your employer may be legally required to keep them for several years after
		you leave — for payroll, tax and employment law. Deleting them could also
		remove proof of hours you worked or leave you were owed.
	</p>
	<p>
		Attendance records in this system cannot be edited or deleted once written,
		by anyone, including administrators. That is a deliberate protection for the
		person the record is about.
	</p>
	<p>
		Your employer will tell you the retention period that applies and can
		usually anonymise a record where it cannot be deleted outright.
	</p>

	<h2>Removing the app instead</h2>
	<p>
		Uninstalling the app removes it from your phone and stops notifications. It
		does not delete your account or your records — for that, use the steps
		above.
	</p>

	<h2>If you are not satisfied</h2>
	<p>
		If your employer refuses a request you believe you are entitled to make, you
		can usually complain to the data protection authority where you live — in the
		UK, the Information Commissioner's Office.
	</p>

	<div class="note">
		<strong>For whoever publishes this app.</strong> Google Play requires a
		reachable URL like this one for any app with accounts, stating what is
		deleted and what is retained. Fill in your organisation's contact address
		before publishing, and check the retention wording against the law that
		applies to you.
	</div>
@endsection
