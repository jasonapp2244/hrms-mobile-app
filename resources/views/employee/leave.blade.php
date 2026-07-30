@extends('layouts.employee')
@section('title', 'My Leave')

@section('content')

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
	<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

{{-- Balances --}}
<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0"><i class="ti ti-calendar-off me-1 text-primary"></i>My Balances <span class="text-muted small">{{ date('Y') }}</span></h5>
		@if($types->isNotEmpty())
			<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#applyModal">
				<i class="ti ti-plus me-1"></i>Apply for Leave
			</button>
		@endif
	</div>
	<div class="card-body">
		@forelse($balances as $row)
			@php
				$type = $row['type']; $bal = $row['balance'];
				$fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
			@endphp
			<div class="d-flex align-items-center justify-content-between mb-1">
				<div>
					<span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:{{ $type->color }}"></span>
					<strong>{{ $type->name }}</strong>
					@unless($type->is_paid)<span class="badge bg-secondary ms-1">Unpaid</span>@endunless
				</div>
				<div class="text-end small">
					@if($bal->total > 0)
						<strong>{{ $fmt($bal->available) }}</strong> left
						<span class="text-muted">of {{ $fmt($bal->total) }}</span>
					@else
						<span class="text-muted">No fixed allowance</span>
					@endif
				</div>
			</div>
			<div class="progress mb-3" style="height:6px">
				<div class="progress-bar" role="progressbar"
					style="width: {{ $bal->total > 0 ? min(100, $bal->used_pct) : 0 }}%; background:{{ $type->color }}"></div>
			</div>
		@empty
			<p class="text-muted mb-0">No leave types have been set up yet. Please contact HR.</p>
		@endforelse
	</div>
</div>

{{-- My requests --}}
<div class="card">
	<div class="card-header"><h5 class="mb-0">My Requests</h5></div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Type</th>
						<th>Dates</th>
						<th>Days</th>
						<th>Status</th>
						<th class="text-end">Action</th>
					</tr>
				</thead>
				<tbody>
					@forelse($requests as $r)
					<tr>
						<td>
							<span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:{{ $r->leaveType->color }}"></span>
							{{ $r->leaveType->name }}
							@if($r->is_half_day)
								<span class="badge bg-light text-dark ms-1">{{ $r->half_day_period === 'second_half' ? '2nd half' : '1st half' }}</span>
							@endif
							@if($r->reason)<div class="text-muted small">{{ Str::limit($r->reason, 60) }}</div>@endif
						</td>
						<td>
							{{ $r->start_date->format('M j, Y') }}
							@if(! $r->start_date->isSameDay($r->end_date))
								<span class="text-muted">→</span> {{ $r->end_date->format('M j, Y') }}
							@endif
						</td>
						<td>{{ rtrim(rtrim(number_format($r->days, 1), '0'), '.') }}</td>
						<td>
							<span class="badge bg-{{ $r->status_badge }}">{{ $r->status_label }}</span>
							@if($r->decision_note)<div class="text-muted small">{{ Str::limit($r->decision_note, 50) }}</div>@endif
						</td>
						<td class="text-end">
							@if($r->isCancellable())
								<form action="{{ route('employee.leave.cancel', $r) }}" method="POST"
									onsubmit="return confirm('Withdraw this leave request?');">
									@csrf
									<button type="submit" class="btn btn-sm btn-outline-danger">Withdraw</button>
								</form>
							@else
								<span class="text-muted small">—</span>
							@endif
						</td>
					</tr>
					@empty
					<tr><td colspan="5" class="text-center text-muted">You have not requested any leave yet.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $requests->links() }}
	</div>
</div>

{{-- Apply modal --}}
<div class="modal fade" id="applyModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<form action="{{ route('employee.leave.store') }}" method="POST" id="applyForm">
				@csrf
				<div class="modal-header">
					<h5 class="modal-title">Apply for Leave</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="mb-3">
						<label class="form-label">Leave Type <span class="text-danger">*</span></label>
						<select name="leave_type_id" id="leaveType" class="form-select" required>
							<option value="">Select…</option>
							@foreach($types as $t)
								<option value="{{ $t->id }}" data-half="{{ $t->allow_half_day ? 1 : 0 }}"
									@selected(old('leave_type_id') == $t->id)>{{ $t->name }}</option>
							@endforeach
						</select>
					</div>

					<div class="row g-3">
						<div class="col-6">
							<label class="form-label">From <span class="text-danger">*</span></label>
							<input type="date" name="start_date" id="startDate" class="form-control" value="{{ old('start_date') }}" required>
						</div>
						<div class="col-6">
							<label class="form-label">To <span class="text-danger">*</span></label>
							<input type="date" name="end_date" id="endDate" class="form-control" value="{{ old('end_date') }}" required>
						</div>
					</div>

					<div class="form-check mt-3" id="halfDayWrap">
						<input type="hidden" name="is_half_day" value="0">
						<input class="form-check-input" type="checkbox" name="is_half_day" value="1" id="isHalfDay" @checked(old('is_half_day'))>
						<label class="form-check-label" for="isHalfDay">Half day only</label>
					</div>

					<div class="mt-2 d-none" id="halfPeriodWrap">
						<label class="form-label">Which half?</label>
						<select name="half_day_period" class="form-select">
							<option value="first_half" @selected(old('half_day_period') === 'first_half')>First half (morning)</option>
							<option value="second_half" @selected(old('half_day_period') === 'second_half')>Second half (afternoon)</option>
						</select>
					</div>

					<div class="mt-3">
						<label class="form-label">Reason</label>
						<textarea name="reason" class="form-control" rows="3" maxlength="1000"
							placeholder="Optional — helps whoever reviews this">{{ old('reason') }}</textarea>
					</div>

					<p class="text-muted small mb-0 mt-3">
						<i class="ti ti-info-circle me-1"></i>Weekends and company holidays inside your dates are
						not deducted from your balance.
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Submit Request</button>
				</div>
			</form>
		</div>
	</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
	var typeSel  = document.getElementById('leaveType'),
		half     = document.getElementById('isHalfDay'),
		halfWrap = document.getElementById('halfDayWrap'),
		periodWrap = document.getElementById('halfPeriodWrap'),
		start    = document.getElementById('startDate'),
		end      = document.getElementById('endDate');

	// Half day is only offered when the chosen type allows it, and is always a
	// single date — the server enforces both, this just avoids a pointless
	// round trip to be told so.
	function syncType() {
		var opt = typeSel.options[typeSel.selectedIndex],
			allowed = opt && opt.dataset.half === '1';
		halfWrap.classList.toggle('d-none', !allowed);
		if (!allowed) { half.checked = false; }
		syncHalf();
	}

	function syncHalf() {
		periodWrap.classList.toggle('d-none', !half.checked);
		end.readOnly = half.checked;
		if (half.checked) { end.value = start.value; }
	}

	typeSel.addEventListener('change', syncType);
	half.addEventListener('change', syncHalf);
	start.addEventListener('change', function () {
		// Keep the end date sane: it can never precede the start.
		if (half.checked || !end.value || end.value < start.value) { end.value = start.value; }
	});

	syncType();
})();
</script>
@endpush
