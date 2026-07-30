@extends('layouts.employee')
@section('title', 'Shift Swaps')

@section('content')

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
	<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

{{-- Waiting on me --}}
@if($incoming->isNotEmpty())
<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0"><i class="ti ti-arrows-exchange me-1 text-warning"></i>Waiting on You</h5>
		<span class="badge bg-warning">{{ $incoming->count() }}</span>
	</div>
	<div class="card-body">
		@foreach($incoming as $s)
			<div class="border rounded p-3 mb-2">
				<div>
					<strong>{{ $s->requester->full_name }}</strong> would like to swap.
				</div>
				<div class="small text-muted mt-1">
					They give up <strong>{{ $s->requester_date->format('D, M j') }}</strong>
					and take your <strong>{{ $s->target_date->format('D, M j') }}</strong>.
					@if($s->isSameDay())<span class="badge bg-light text-dark ms-1">same day</span>@endif
				</div>
				@if($s->reason)<div class="small mt-2"><span class="text-muted">Reason:</span> {{ $s->reason }}</div>@endif
				<div class="mt-2 d-flex gap-2">
					<form action="{{ route('employee.swaps.accept', $s) }}" method="POST">
						@csrf
						<button type="submit" class="btn btn-sm btn-success"><i class="ti ti-check me-1"></i>Accept</button>
					</form>
					<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#decline{{ $s->id }}">Decline</button>
				</div>
			</div>

			<div class="modal fade" id="decline{{ $s->id }}" tabindex="-1" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<form action="{{ route('employee.swaps.decline', $s) }}" method="POST">
							@csrf
							<div class="modal-header">
								<h5 class="modal-title">Decline swap</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body">
								<label class="form-label">Note <span class="text-muted">(optional)</span></label>
								<textarea name="response_note" class="form-control" rows="3" maxlength="1000"
									placeholder="{{ $s->requester->first_name }} will see this."></textarea>
							</div>
							<div class="modal-footer">
								<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
								<button type="submit" class="btn btn-danger">Decline</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		@endforeach
	</div>
</div>
@endif

{{-- Request a swap --}}
<div class="card mb-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0"><i class="ti ti-arrows-exchange me-1 text-primary"></i>My Swap Requests</h5>
		@if($colleagues->isNotEmpty())
			<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#askModal">
				<i class="ti ti-plus me-1"></i>Request a Swap
			</button>
		@endif
	</div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr><th>With</th><th>Your day</th><th>Their day</th><th>Status</th><th class="text-end">Action</th></tr>
				</thead>
				<tbody>
					@forelse($mine as $s)
					<tr>
						<td>{{ $s->target->full_name }}</td>
						<td>{{ $s->requester_date->format('D, M j') }}</td>
						<td>{{ $s->target_date->format('D, M j') }}</td>
						<td>
							<span class="badge bg-{{ $s->status_badge }}">{{ $s->status_label }}</span>
							@if($s->decision_note)<div class="text-muted small">{{ Str::limit($s->decision_note, 50) }}</div>
							@elseif($s->response_note)<div class="text-muted small">{{ Str::limit($s->response_note, 50) }}</div>@endif
						</td>
						<td class="text-end">
							@if($s->isOpen())
								<form action="{{ route('employee.swaps.cancel', $s) }}" method="POST"
									onsubmit="return confirm('Withdraw this swap request?');">
									@csrf
									<button type="submit" class="btn btn-sm btn-outline-danger">Withdraw</button>
								</form>
							@else
								<span class="text-muted small">—</span>
							@endif
						</td>
					</tr>
					@empty
					<tr><td colspan="5" class="text-center text-muted">You have not requested any swaps.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>

@if($history->isNotEmpty())
<div class="card">
	<div class="card-header"><h5 class="mb-0">Swaps You Responded To</h5></div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead><tr><th>From</th><th>Their day</th><th>Your day</th><th>Status</th></tr></thead>
				<tbody>
					@foreach($history as $s)
					<tr>
						<td>{{ $s->requester->full_name }}</td>
						<td>{{ $s->requester_date->format('D, M j') }}</td>
						<td>{{ $s->target_date->format('D, M j') }}</td>
						<td><span class="badge bg-{{ $s->status_badge }}">{{ $s->status_label }}</span></td>
					</tr>
					@endforeach
				</tbody>
			</table>
		</div>
	</div>
</div>
@endif

{{-- Ask modal --}}
<div class="modal fade" id="askModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<form action="{{ route('employee.swaps.store') }}" method="POST">
				@csrf
				<div class="modal-header">
					<h5 class="modal-title">Request a Swap</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="mb-3">
						<label class="form-label">The day you want covered <span class="text-danger">*</span></label>
						<input type="date" name="requester_date" class="form-control" value="{{ old('requester_date') }}" required>
						<div class="form-text">You have to be rostered to work it.</div>
					</div>
					<div class="mb-3">
						<label class="form-label">Colleague <span class="text-danger">*</span></label>
						<select name="target_id" class="form-select" required>
							<option value="">Select…</option>
							@foreach($colleagues as $c)
								<option value="{{ $c->id }}" @selected(old('target_id') == $c->id)>{{ $c->full_name }}</option>
							@endforeach
						</select>
					</div>
					<div class="mb-3">
						<label class="form-label">The day you would take instead <span class="text-danger">*</span></label>
						<input type="date" name="target_date" class="form-control" value="{{ old('target_date') }}" required>
						<div class="form-text">
							Use the same date on both to simply trade shifts for that day.
						</div>
					</div>
					<div class="mb-0">
						<label class="form-label">Reason</label>
						<textarea name="reason" class="form-control" rows="3" maxlength="1000"
							placeholder="Optional — your colleague and the approver both see this">{{ old('reason') }}</textarea>
					</div>
					<p class="text-muted small mb-0 mt-3">
						<i class="ti ti-info-circle me-1"></i>Your colleague accepts first, then a manager approves.
						Nothing changes on the roster until both have happened.
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Send Request</button>
				</div>
			</form>
		</div>
	</div>
</div>
@endsection
