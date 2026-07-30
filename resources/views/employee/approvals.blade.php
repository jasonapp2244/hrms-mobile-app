@extends('layouts.employee')
@section('title', 'Team Approvals')

@section('content')

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
	<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0"><i class="ti ti-checklist me-1 text-primary"></i>Waiting on You</h5>
		<span class="badge bg-{{ $pending->isEmpty() ? 'secondary' : 'warning' }}">{{ $pending->count() }}</span>
	</div>
	<div class="card-body">
		@forelse($pending as $r)
			@php $clash = $clashes[$r->id] ?? collect(); @endphp
			<div class="border rounded p-3 mb-3">
				<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
					<div>
						<strong>{{ $r->employee->full_name }}</strong>
						<span class="text-muted small">{{ $r->employee->employee_code }}</span>
						<div class="mt-1">
							<span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:{{ $r->leaveType->color }}"></span>
							{{ $r->leaveType->name }}
							@if($r->is_half_day)
								<span class="badge bg-light text-dark ms-1">{{ $r->half_day_period === 'second_half' ? '2nd half' : '1st half' }}</span>
							@endif
						</div>
						<div class="text-muted small mt-1">
							{{ $r->start_date->format('D, M j, Y') }}
							@if(! $r->start_date->isSameDay($r->end_date))
								&rarr; {{ $r->end_date->format('D, M j, Y') }}
							@endif
							&middot; {{ rtrim(rtrim(number_format($r->days, 1), '0'), '.') }} day(s)
						</div>
						@if($r->reason)
							<div class="mt-2 small"><span class="text-muted">Reason:</span> {{ $r->reason }}</div>
						@endif
					</div>
					<div class="text-end">
						<button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approve{{ $r->id }}">
							<i class="ti ti-check me-1"></i>Approve
						</button>
						<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject{{ $r->id }}">
							<i class="ti ti-x me-1"></i>Reject
						</button>
					</div>
				</div>

				@if($clash->isNotEmpty())
					{{-- Cover check: who else on the team is already off then. --}}
					<div class="alert alert-warning mt-3 mb-0 py-2 small">
						<i class="ti ti-users me-1"></i>
						Also off over these dates:
						{{ $clash->map(fn ($c) => $c->employee->full_name . ' (' . $c->start_date->format('M j') . '–' . $c->end_date->format('M j') . ')')->implode(', ') }}
					</div>
				@endif
			</div>

			{{-- Approve --}}
			<div class="modal fade" id="approve{{ $r->id }}" tabindex="-1" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<form action="{{ route('employee.approvals.approve', $r) }}" method="POST">
							@csrf
							<div class="modal-header">
								<h5 class="modal-title">Approve {{ $r->employee->first_name }}'s leave</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<p class="text-muted">
									This passes the request to HR for final approval. The days are only
									deducted from {{ $r->employee->first_name }}'s balance once HR signs off.
								</p>
								<label class="form-label">Note for HR <span class="text-muted">(optional)</span></label>
								<textarea name="manager_note" class="form-control" rows="3" maxlength="1000"
									placeholder="e.g. cover arranged with the rest of the team"></textarea>
							</div>
							<div class="modal-footer">
								<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
								<button type="submit" class="btn btn-success">Approve &amp; Send to HR</button>
							</div>
						</form>
					</div>
				</div>
			</div>

			{{-- Reject --}}
			<div class="modal fade" id="reject{{ $r->id }}" tabindex="-1" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<form action="{{ route('employee.approvals.reject', $r) }}" method="POST">
							@csrf
							<div class="modal-header">
								<h5 class="modal-title">Reject {{ $r->employee->first_name }}'s leave</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<label class="form-label">Reason <span class="text-danger">*</span></label>
								<textarea name="decision_note" class="form-control" rows="3" maxlength="1000" required
									placeholder="{{ $r->employee->first_name }} will see this."></textarea>
							</div>
							<div class="modal-footer">
								<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
								<button type="submit" class="btn btn-danger">Reject Request</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		@empty
			<p class="text-muted mb-0">
				@if($manager->subordinates()->count() === 0)
					Nobody reports to you yet, so there is nothing to approve. HR sets reporting lines
					on the employee record.
				@else
					Nothing waiting. You are all caught up.
				@endif
			</p>
		@endforelse
	</div>
</div>

@if($swaps->isNotEmpty())
<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0"><i class="ti ti-arrows-exchange me-1 text-warning"></i>Shift Swaps to Approve</h5>
		<span class="badge bg-warning">{{ $swaps->count() }}</span>
	</div>
	<div class="card-body">
		<p class="text-muted small">Both employees have already agreed. Approving updates the roster immediately.</p>
		@foreach($swaps as $s)
			<div class="border rounded p-3 mb-2">
				<div>
					<strong>{{ $s->requester->full_name }}</strong>
					<i class="ti ti-arrows-exchange mx-1 text-muted"></i>
					<strong>{{ $s->target->full_name }}</strong>
				</div>
				<div class="small text-muted mt-1">
					{{ $s->requester->first_name }} gives up {{ $s->requester_date->format('D, M j') }},
					{{ $s->target->first_name }} gives up {{ $s->target_date->format('D, M j') }}.
					@if($s->isSameDay())<span class="badge bg-light text-dark ms-1">same day — straight trade</span>@endif
				</div>
				@if($s->reason)<div class="small mt-2"><span class="text-muted">Reason:</span> {{ $s->reason }}</div>@endif
				<div class="mt-2 d-flex gap-2">
					<form action="{{ route('employee.swaps.approve', $s) }}" method="POST">
						@csrf
						<button type="submit" class="btn btn-sm btn-success"><i class="ti ti-check me-1"></i>Approve</button>
					</form>
					<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectSwap{{ $s->id }}">Reject</button>
				</div>
			</div>

			<div class="modal fade" id="rejectSwap{{ $s->id }}" tabindex="-1" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<form action="{{ route('employee.swaps.reject', $s) }}" method="POST">
							@csrf
							<div class="modal-header">
								<h5 class="modal-title">Reject swap</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<label class="form-label">Reason <span class="text-danger">*</span></label>
								<textarea name="decision_note" class="form-control" rows="3" maxlength="1000" required
									placeholder="Both employees see this."></textarea>
							</div>
							<div class="modal-footer">
								<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
								<button type="submit" class="btn btn-danger">Reject Swap</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		@endforeach
	</div>
</div>
@endif

<div class="card">
	<div class="card-header"><h5 class="mb-0">Recently Handled</h5></div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th></tr>
				</thead>
				<tbody>
					@forelse($decided as $r)
					<tr>
						<td>{{ $r->employee->full_name }}</td>
						<td>{{ $r->leaveType->name }}</td>
						<td>
							{{ $r->start_date->format('M j') }}
							@if(! $r->start_date->isSameDay($r->end_date))&rarr; {{ $r->end_date->format('M j') }}@endif
						</td>
						<td>{{ rtrim(rtrim(number_format($r->days, 1), '0'), '.') }}</td>
						<td>
							@if($r->status === 'pending')
								<span class="badge bg-info">With HR</span>
							@else
								<span class="badge bg-{{ $r->status_badge }}">{{ $r->status_label }}</span>
							@endif
						</td>
					</tr>
					@empty
					<tr><td colspan="5" class="text-center text-muted">Nothing decided yet.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
