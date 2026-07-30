@extends('layouts.app')
@section('title','Holiday Calendar')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2"><h2 class="mb-1">Holiday Calendar</h2>
		<nav><ol class="breadcrumb mb-0">
			<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
			<li class="breadcrumb-item">Leave</li>
			<li class="breadcrumb-item active">Holidays</li>
		</ol></nav></div>
	<div class="d-flex align-items-center flex-wrap gap-2">
		<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-plus me-1"></i>Add Holiday</button>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
	<i class="ti ti-info-circle me-1"></i>
	A holiday is <strong>not charged</strong> to an employee's leave balance when it falls inside a
	leave range, and is <strong>never counted as an absence</strong>. Mark a date
	<strong>recurring</strong> when it lands on the same day every year (1 January); leave it off for
	dates that move (Eid), and enter those once per year.
	<div class="mt-1 small">Leave already approved keeps the number of days it was granted — adding a holiday now does not retroactively refund anyone.</div>
</div>

<div class="card">
	<div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
		<h5 class="mb-0">Holidays in {{ $year }}</h5>
		<form method="GET" action="{{ route('holidays.index') }}" class="d-flex align-items-center gap-2">
			<label class="form-label mb-0 text-muted small">Year</label>
			<select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
				@foreach($years as $y)
					<option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
				@endforeach
			</select>
		</form>
	</div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Date</th>
						<th>Day</th>
						<th>Holiday</th>
						<th>Repeats</th>
						<th class="text-end">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse($holidays as $row)
					@php $h = $row['holiday']; $on = $row['observed_on']; @endphp
					<tr class="{{ $on->isPast() ? 'text-muted' : '' }}">
						<td>{{ $on->format('M j, Y') }}</td>
						<td>{{ $on->format('l') }}</td>
						<td>
							{{ $h->name }}
							@if($on->isToday())<span class="badge bg-success ms-1">Today</span>@endif
						</td>
						<td>
							@if($h->is_recurring)
								<span class="badge bg-info">Every year</span>
							@else
								<span class="text-muted">This year only</span>
							@endif
						</td>
						<td class="text-end">
							<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $h->id }}"><i class="ti ti-edit"></i></button>
							<form action="{{ route('holidays.destroy', $h) }}" method="POST" class="d-inline" onsubmit="return confirm('Remove this holiday?');">
								@csrf
								@method('DELETE')
								<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
							</form>
						</td>
					</tr>

					<!-- Edit Modal -->
					<div class="modal fade" id="editModal{{ $h->id }}" tabindex="-1" aria-hidden="true">
						<div class="modal-dialog">
							<div class="modal-content">
								<form action="{{ route('holidays.update', $h) }}" method="POST">
									@csrf
									@method('PUT')
									<div class="modal-header">
										<h5 class="modal-title">Edit Holiday</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										<div class="mb-3">
											<label class="form-label">Name <span class="text-danger">*</span></label>
											<input type="text" name="name" class="form-control" value="{{ old('name', $h->name) }}" required>
										</div>
										<div class="mb-3">
											<label class="form-label">Date <span class="text-danger">*</span></label>
											<input type="date" name="date" class="form-control" value="{{ old('date', $h->date->toDateString()) }}" required>
										</div>
										<div class="form-check">
											{{-- Hidden partner sends 0 when unchecked, so clearing the flag persists. --}}
											<input type="hidden" name="is_recurring" value="0">
											<input class="form-check-input" type="checkbox" name="is_recurring" value="1" id="rec{{ $h->id }}" @checked($h->is_recurring)>
											<label class="form-check-label" for="rec{{ $h->id }}">Repeats every year on this day and month</label>
										</div>
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
										<button type="submit" class="btn btn-primary">Save Changes</button>
									</div>
								</form>
							</div>
						</div>
					</div>
					@empty
					<tr><td colspan="5" class="text-center text-muted">No holidays recorded for {{ $year }}. Add them before staff start booking leave around them.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<form action="{{ route('holidays.store') }}" method="POST">
				@csrf
				<div class="modal-header">
					<h5 class="modal-title">Add Holiday</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="mb-3">
						<label class="form-label">Name <span class="text-danger">*</span></label>
						<input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. New Year's Day" required>
					</div>
					<div class="mb-3">
						<label class="form-label">Date <span class="text-danger">*</span></label>
						<input type="date" name="date" class="form-control" value="{{ old('date', $year . '-01-01') }}" required>
					</div>
					<div class="form-check">
						<input type="hidden" name="is_recurring" value="0">
						<input class="form-check-input" type="checkbox" name="is_recurring" value="1" id="addRec" checked>
						<label class="form-check-label" for="addRec">Repeats every year on this day and month</label>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Save Holiday</button>
				</div>
			</form>
		</div>
	</div>
</div>
@endsection
