{{--
	One person and everybody under them.

	Recursive: the partial includes itself for each report. `depth` is carried
	only as a guard — the model refuses a reporting loop, but a chart that
	recurses forever because of a row written directly into the database is a
	blank page and a memory-limit error, which is far harder to diagnose than a
	truncated tree with a note on it.
--}}
@php $reports = $byManager[$person->id] ?? collect(); @endphp

<li class="mb-2">
	<div class="d-flex align-items-center gap-2">
		@if($person->photo_url)
			<img src="{{ $person->photo_url }}" class="rounded-circle" width="32" height="32"
			     style="object-fit:cover" alt="">
		@else
			<span class="avatar avatar-sm bg-primary-transparent rounded-circle d-inline-flex align-items-center justify-content-center"
			      style="width:32px;height:32px">
				{{ strtoupper(substr($person->first_name, 0, 1) . substr($person->last_name ?? '', 0, 1)) }}
			</span>
		@endif

		<div>
			<a href="{{ route('employees.show', $person) }}" class="fw-semibold">{{ $person->full_name }}</a>
			<div class="text-muted fs-12">
				{{ $person->designation->name ?? 'No title' }}
				@if($person->department) · {{ $person->department->name }} @endif
				@if($reports->count()) · <span class="badge bg-light text-dark">{{ $reports->count() }} report(s)</span> @endif
			</div>
		</div>
	</div>

	@if($reports->count())
		@if($depth >= 10)
			<ul class="mt-2"><li class="text-muted fs-13">
				Further levels not shown — the reporting line is more than ten deep, which usually means a bad record.
			</li></ul>
		@else
			<ul class="mt-2" style="border-left:2px solid var(--bs-border-color); padding-left:1rem; list-style:none">
				@foreach($reports as $report)
					@include('employees.partials.org-node', ['person' => $report, 'byManager' => $byManager, 'depth' => $depth + 1])
				@endforeach
			</ul>
		@endif
	@endif
</li>
