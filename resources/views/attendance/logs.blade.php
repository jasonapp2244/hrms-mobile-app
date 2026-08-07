@extends('layouts.app')
@section('title', 'Attendance Logs')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance Logs</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">Attendance Logs</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		@can('manage-attendance')
		<button type="button" class="btn btn-primary mb-2 me-2" data-bs-toggle="modal" data-bs-target="#manualEntryModal">
			<i class="ti ti-plus me-1"></i>Add entry
		</button>
		@endcan
		<a href="{{ route('attendance.report') }}" class="btn btn-outline-secondary mb-2"><i class="ti ti-file-report me-1"></i>Report</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

@if($errors->any())
<div class="alert alert-danger">
	<ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<!-- Filters -->
<div class="card">
	<div class="card-body">
		<form method="GET" action="{{ route('attendance.logs') }}" class="row g-3 align-items-end">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Date</label>
				<input type="date" name="date" class="form-control" value="{{ request('date') }}">
			</div>
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Office</label>
				<select name="office_id" class="form-select">
					<option value="">All Offices</option>
					@foreach($offices as $o)
						<option value="{{ $o->id }}" {{ request('office_id')==$o->id ? 'selected' : '' }}>{{ $o->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Type</label>
				<select name="type" class="form-select">
					<option value="">All</option>
					<option value="in" {{ request('type')=='in' ? 'selected' : '' }}>IN</option>
					<option value="out" {{ request('type')=='out' ? 'selected' : '' }}>OUT</option>
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Status</label>
				<select name="status" class="form-select">
					<option value="">All</option>
					<option value="ontime" {{ request('status')=='ontime' ? 'selected' : '' }}>On Time</option>
					<option value="late" {{ request('status')=='late' ? 'selected' : '' }}>Late</option>
					<option value="early_leave" {{ request('status')=='early_leave' ? 'selected' : '' }}>Early Leave</option>
					<option value="invalid" {{ request('status')=='invalid' ? 'selected' : '' }}>Invalid</option>
				</select>
			</div>
			<div class="col-md-2 col-sm-12 d-flex">
				<button type="submit" class="btn btn-primary me-2"><i class="ti ti-filter me-1"></i>Filter</button>
					<input type="hidden" name="show_voided" value="0">
					<label class="btn btn-light me-2 mb-0">
						<input type="checkbox" name="show_voided" value="1" class="form-check-input me-1"
							onchange="this.form.submit()" {{ request()->boolean('show_voided') ? 'checked' : '' }}>
						Voided
					</label>
				<a href="{{ route('attendance.logs') }}" class="btn btn-light">Clear</a>
			</div>
		</form>
	</div>
</div>

<!-- Logs table -->
<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Employee Code</th>
						<th>Office</th>
						<th>Type</th>
						<th>Status</th>
						<th>Time</th>
						<th>Date</th>
						<th>Source</th>
						<th>Location</th>
						<th>IP Address</th>
							@can('manage-attendance')<th class="text-end">Actions</th>@endcan
					</tr>
				</thead>
				<tbody>
					@forelse($logs as $log)
					{{-- A voided punch is dimmed rather than hidden when it is on
						 screen at all, so "why is this person two hours short"
						 has a visible answer instead of a missing row. --}}
					<tr class="{{ $log->isVoided() ? 'opacity-50' : '' }}">
						<td>{{ $log->employee->full_name ?? 'Unknown' }}</td>
						<td>{{ $log->employee->employee_code ?? '' }}</td>
						<td>{{ $log->office->name ?? '' }}</td>
						<td>
							<span class="badge bg-{{ $log->type=='in'?'success':'secondary' }}">{{ strtoupper($log->type) }}</span>
							@if($log->isVoided())
								<span class="badge bg-danger ms-1"
									title="Voided by {{ $log->voided_by_label ?? 'unknown' }} on {{ $log->voided_at?->format('d M Y H:i') }} — {{ $log->void_reason }}">Voided</span>
							@endif
						</td>
						<td><span class="badge bg-{{ $log->status=='late'?'warning':($log->status=='ontime'?'success':'secondary') }}">{{ $log->status }}</span></td>
						<td>{{ $log->scanned_at->format('h:i A') }}</td>
						<td>{{ $log->work_date->format('m/d/Y') }}</td>
						<td>{{ $log->source }}</td>
						<td>
							@if($log->latitude && $log->longitude)
								<a href="https://www.google.com/maps?q={{ $log->latitude }},{{ $log->longitude }}" target="_blank" rel="noopener"
									class="badge bg-info-transparent text-info text-decoration-none"
									title="{{ $log->latitude }}, {{ $log->longitude }}">
									<i class="ti ti-map-pin me-1"></i>View map
								</a>
							@else
								<span class="badge bg-light text-muted" title="Location not shared by the employee's device">—</span>
							@endif
						</td>
						<td><small class="text-muted">{{ $log->ip_address ?? '—' }}</small></td>
						@can('manage-attendance')
						<td class="text-end">
							@if($log->isVoided())
								<small class="text-muted d-block">by {{ $log->voided_by_label ?? '—' }}</small>
								<small class="text-muted">{{ Str::limit($log->void_reason, 40) }}</small>
							@else
								<button type="button" class="btn btn-sm btn-outline-danger js-void"
									data-url="{{ route('attendance.void', $log) }}"
									data-who="{{ $log->employee->full_name ?? 'Unknown' }}"
									data-what="{{ strtoupper($log->type) }} at {{ $log->scanned_at->format('d M Y h:i A') }}">
									Void
								</button>
							@endif
						</td>
						@endcan
					</tr>
					@empty
					<tr>
						<td colspan="@can('manage-attendance')11@else 10 @endcan" class="text-center text-muted py-4">No attendance logs found.</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $logs->links() }}
	</div>
</div>

@can('manage-attendance')
{{-- Manual entry (A4.12). For the punch that was never recorded: a forgotten
	 check-out, a failed badge, a day worked off-site. --}}
<div class="modal fade" id="manualEntryModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form method="POST" action="{{ route('attendance.manual') }}" class="modal-content">
			@csrf
			<div class="modal-header">
				<h5 class="modal-title"><i class="ti ti-plus me-1"></i>Add attendance entry</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="mb-3">
					<label class="form-label" for="me_employee">Employee</label>
					<select name="employee_id" id="me_employee" class="form-select" required>
						<option value="">Choose…</option>
						@foreach($employees as $e)
							<option value="{{ $e->id }}" {{ old('employee_id')==$e->id ? 'selected' : '' }}>
								{{ $e->first_name }} {{ $e->last_name }} ({{ $e->employee_code }})
							</option>
						@endforeach
					</select>
				</div>
				<div class="row">
					<div class="col-6 mb-3">
						<label class="form-label" for="me_office">Office</label>
						<select name="office_id" id="me_office" class="form-select" required>
							@foreach($offices as $o)
								<option value="{{ $o->id }}" {{ old('office_id')==$o->id ? 'selected' : '' }}>{{ $o->name }}</option>
							@endforeach
						</select>
					</div>
					<div class="col-6 mb-3">
						<label class="form-label" for="me_type">Type</label>
						<select name="type" id="me_type" class="form-select" required>
							<option value="in" {{ old('type')=='in' ? 'selected' : '' }}>IN</option>
							<option value="out" {{ old('type')=='out' ? 'selected' : '' }}>OUT</option>
						</select>
					</div>
				</div>
				<div class="mb-3">
					<label class="form-label" for="me_at">Date &amp; time</label>
					<input type="datetime-local" name="scanned_at" id="me_at" class="form-control"
						value="{{ old('scanned_at') }}" required>
					<div class="form-text">The employee's local time. On-time / late is judged against their shift for that day.</div>
				</div>
				<div class="mb-0">
					<label class="form-label" for="me_reason">Reason</label>
					<textarea name="reason" id="me_reason" class="form-control" rows="2" minlength="5" maxlength="500"
						placeholder="e.g. Badge reader failed; confirmed with line manager" required>{{ old('reason') }}</textarea>
					<div class="form-text">Recorded on the punch and in the audit trail. Required.</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary">Record entry</button>
			</div>
		</form>
	</div>
</div>

{{-- Void. One modal reused for every row; the form action is swapped in from
	 the clicked button so there is not a modal per punch in the DOM. --}}
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form method="POST" action="" class="modal-content" id="voidForm">
			@csrf
			<div class="modal-header">
				<h5 class="modal-title"><i class="ti ti-ban me-1"></i>Void this punch</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="mb-2"><strong id="voidWho"></strong><br><span class="text-muted" id="voidWhat"></span></p>
				<div class="alert alert-warning py-2">
					The record is kept and struck out, not deleted. It stops counting towards worked hours.
				</div>
				<label class="form-label" for="void_reason">Reason</label>
				<textarea name="reason" id="void_reason" class="form-control" rows="2" minlength="5" maxlength="500"
					placeholder="e.g. Duplicate scan; employee had already checked in" required></textarea>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-danger">Void punch</button>
			</div>
		</form>
	</div>
</div>

@push('scripts')
<script>
	(function () {
		var modalEl = document.getElementById('voidModal');
		if (!modalEl) return;
		var modal = new bootstrap.Modal(modalEl);

		document.querySelectorAll('.js-void').forEach(function (button) {
			button.addEventListener('click', function () {
				document.getElementById('voidForm').action = button.dataset.url;
				document.getElementById('voidWho').textContent = button.dataset.who;
				document.getElementById('voidWhat').textContent = button.dataset.what;
				document.getElementById('void_reason').value = '';
				modal.show();
			});
		});
	})();
</script>
@endpush
@endcan
@endsection
