{{-- One day. Shared by the flat list and the grouped ones so a weekly view and
	 a daily view cannot disagree about how a day reads. --}}
<tr>
	<td class="text-nowrap">
		<span class="fw-semibold">{{ \Carbon\Carbon::parse($day['date'])->format('d M Y') }}</span>
		<small class="text-muted d-block">{{ $day['weekday'] }}</small>
	</td>
	<td class="text-nowrap">{{ $day['first_in'] ? \App\Support\Clock::time($day['first_in']) : '—' }}</td>
	<td class="text-nowrap text-muted">{{ $day['break_start'] ? \App\Support\Clock::time($day['break_start']) : '—' }}</td>
	<td class="text-nowrap text-muted">{{ $day['break_end'] ? \App\Support\Clock::time($day['break_end']) : '—' }}</td>
	<td class="text-nowrap text-end">{{ $day['break_minutes'] > 0 ? \App\Support\Clock::duration($day['break_minutes']) : '—' }}</td>
	<td class="text-nowrap">{{ $day['last_out'] ? \App\Support\Clock::time($day['last_out']) : '—' }}</td>
	<td class="text-nowrap text-end fw-semibold">{{ $day['worked_minutes'] > 0 ? \App\Support\Clock::duration($day['worked_minutes']) : '—' }}</td>
	<td>
		@php
			$badge = match ($day['status']) {
				'present' => 'success',
				'absent'  => 'danger',
				'leave'   => 'info',
				'holiday' => 'primary',
				default   => 'secondary',
			};
		@endphp
		<span class="badge bg-{{ $badge }}-transparent text-{{ $badge }}">{{ ucfirst(str_replace('_', ' ', $day['status'])) }}</span>
	</td>
	<td class="text-nowrap">
		@if($day['late'])<span class="badge bg-warning-transparent text-warning">Late</span>@endif
		@if($day['early_leave'])<span class="badge bg-warning-transparent text-warning">Left early</span>@endif
		@if(! $day['late'] && ! $day['early_leave'])<span class="text-muted">—</span>@endif
	</td>
	<td class="text-muted fs-13">{{ $day['remarks'] ?? ($day['holiday'] ?? '—') }}</td>
</tr>