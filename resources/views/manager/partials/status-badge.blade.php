{{-- One vocabulary for a day's status, everywhere a manager sees one.

	 The words come from AttendanceService::dayStatus, which is also what the
	 employee's own history and the app's team view render — so a manager and
	 the person they manage can never be reading two different labels for the
	 same day. Lateness rides alongside the status rather than replacing it:
	 somebody late is still present, and a badge that said only "late" would
	 lose that. --}}
@php
	$badges = [
		'present' => ['Present', 'success'],
		'leave'   => ['On leave', 'primary'],
		'holiday' => ['Holiday', 'secondary'],
		'day_off' => ['Day off', 'secondary'],
		'weekend' => ['Weekend', 'light text-muted'],
		'absent'  => ['Absent', 'danger'],
	];
	[$label, $tone] = $badges[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'secondary'];
@endphp
<span class="badge bg-{{ $tone }}">{{ $label }}</span>
@if(! empty($late))
<span class="badge bg-warning ms-1">Late</span>
@endif
