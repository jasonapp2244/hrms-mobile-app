@extends('layouts.app')
@section('title','Shift Swaps')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2"><h2 class="mb-1">Shift Swaps</h2>
		<nav><ol class="breadcrumb mb-0">
			<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
			<li class="breadcrumb-item"><a href="{{ route('shifts.index') }}">Shifts &amp; Schedule</a></li>
			<li class="breadcrumb-item active">Swaps</li>
		</ol></nav></div>
	<div class="d-flex align-items-center flex-wrap gap-2">
		<a href="{{ route('shifts.roster') }}" class="btn btn-outline-primary"><i class="ti ti-calendar-week me-1"></i>Roster</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="row g-3 mb-3">
	@php
		$tiles = [
			['Awaiting Colleague', $stats['awaiting_colleague'], 'ti-hourglass', 'secondary'],
			['Awaiting Approval', $stats['awaiting_approval'], 'ti-clock-hour-4', 'warning'],
			['Approved', $stats['approved'], 'ti-check', 'success'],
		];
	@endphp
	@foreach($tiles as [$label, $value, $icon, $tone])
	<div class="col-lg-4 col-sm-6">
		<div class="card h-100">
			<div class="card-body d-flex align-items-center">
				<span class="avatar avatar-lg bg-{{ $tone }}-transparent text-{{ $tone }} rounded-circle me-3">
					<i class="ti {{ $icon }} fs-20"></i>
				</span>
				<div><p class="mb-1 text-muted">{{ $label }}</p><h4 class="mb-0">{{ $value }}</h4></div>
			</div>
		</div>
	</div>
	@endforeach
</div>

<div class="card">
	<div class="card-body">
		<form method="GET" action="{{ route('shift-swaps.index') }}" class="row g-3 align-items-end">
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Status</label>
				<select name="status" class="form-select">
					<option value="">All</option>
					@foreach(\App\Models\ShiftSwapRequest::STATUSES as $key => $label)
						<option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ $label }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-3 col-sm-6 d-flex">
				<button type="submit" class="btn btn-primary me-2"><i class="ti ti-filter me-1"></i>Filter</button>
				<a href="{{ route('shift-swaps.index') }}" class="btn btn-light">Clear</a>
			</div>
		</form>
	</div>
</div>

<div class="card mt-3">
	<div class="card-header"><h5 class="mb-0">All Swap Requests</h5></div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Requester</th><th>Gives up</th>
						<th>Colleague</th><th>Gives up</th>
						<th>Reason</th><th>Status</th><th>Decided By</th>
						<th class="text-end">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse($swaps as $s)
					<tr>
						<td>
							<strong>{{ $s->requester?->full_name ?? '—' }}</strong>
							<div class="text-muted small">{{ $s->requester?->department?->name }}</div>
						</td>
						<td>{{ $s->requester_date->format('D, M j') }}</td>
						<td>{{ $s->target?->full_name ?? '—' }}</td>
						<td>
							{{ $s->target_date->format('D, M j') }}
							@if($s->isSameDay())<div class="text-muted small">same day</div>@endif
						</td>
						<td>{{ $s->reason ? Str::limit($s->reason, 30) : '—' }}</td>
						<td>
							<span class="badge bg-{{ $s->status_badge }}">{{ $s->status_label }}</span>
							@if($s->decision_note)<div class="text-muted small">{{ Str::limit($s->decision_note, 40) }}</div>@endif
						</td>
						<td>
							@if($s->approved_at)
								{{ $s->approver?->name ?? 'System' }}
								<div class="text-muted small">{{ $s->approved_at->format('M j, Y') }}</div>
							@else
								<span class="text-muted">—</span>
							@endif
						</td>
						<td class="text-end text-nowrap">
							@if($s->isAwaitingApproval())
								<form action="{{ route('shift-swaps.approve', $s) }}" method="POST" class="d-inline"
									onsubmit="return confirm('Approve this swap? The roster is updated immediately.');">
									@csrf
									<button type="submit" class="btn btn-sm btn-success">Approve</button>
								</form>
								<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rej{{ $s->id }}">Reject</button>
							@elseif($s->isAwaitingColleague())
								<span class="text-muted small">with {{ $s->target?->first_name }}</span>
							@else
								<span class="text-muted small">—</span>
							@endif
						</td>
					</tr>

					@if($s->isAwaitingApproval())
					<div class="modal fade" id="rej{{ $s->id }}" tabindex="-1" aria-hidden="true">
						<div class="modal-dialog">
							<div class="modal-content">
								<form action="{{ route('shift-swaps.reject', $s) }}" method="POST">
									@csrf
									<div class="modal-header">
										<h5 class="modal-title">Reject swap</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										<p class="mb-2">{{ $s->requester?->full_name }} &harr; {{ $s->target?->full_name }}</p>
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
					@endif
					@empty
					<tr><td colspan="8" class="text-center text-muted">No swap requests yet.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $swaps->links() }}
	</div>
</div>
@endsection
