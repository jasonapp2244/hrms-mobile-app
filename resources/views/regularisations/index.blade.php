@extends('layouts.app')
@section('title', 'Attendance Corrections')

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2">
		<h2 class="mb-1">Attendance Corrections</h2>
		<nav>
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
				<li class="breadcrumb-item">Attendance</li>
				<li class="breadcrumb-item active">Corrections</li>
			</ol>
		</nav>
	</div>
	<div class="d-flex align-items-center flex-wrap">
		<a href="{{ route('attendance.logs') }}" class="btn btn-outline-secondary mb-2"><i class="ti ti-list me-1"></i>Attendance logs</a>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card">
	<div class="card-body">
		<ul class="nav nav-pills gap-2 mb-0">
			<li class="nav-item">
				<a class="nav-link {{ ! request('status') ? 'active' : '' }}"
					href="{{ route('attendance.regularisations') }}">
					Pending <span class="badge bg-white text-dark ms-1">{{ $pendingCount }}</span>
				</a>
			</li>
			@foreach(['approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Withdrawn'] as $key => $label)
			<li class="nav-item">
				<a class="nav-link {{ request('status') === $key ? 'active' : '' }}"
					href="{{ route('attendance.regularisations', ['status' => $key]) }}">{{ $label }}</a>
			</li>
			@endforeach
		</ul>
	</div>
</div>

<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Employee</th>
						<th>What they are asking for</th>
						<th>Reason</th>
						<th>Raised</th>
						<th>Status</th>
						<th class="text-end">Decision</th>
					</tr>
				</thead>
				<tbody>
					@forelse($requests as $req)
					<tr>
						<td>
							<div class="fw-semibold">{{ $req->employee->full_name ?? 'Unknown' }}</div>
							<small class="text-muted">{{ $req->employee->employee_code ?? '' }}</small>
						</td>
						<td>
							<span class="badge bg-{{ $req->type === 'in' ? 'success' : 'secondary' }}">{{ strtoupper($req->type) }}</span>
							<span class="ms-1">{{ $req->requested_at->format('d M Y, h:i A') }}</span>
							@if($req->challengesAPunch())
								{{-- The distinction the approver needs: this one strikes an
									 existing reading out, the other only adds a missing one. --}}
								<div class="small text-warning mt-1">
									<i class="ti ti-arrow-narrow-right me-1"></i>replaces
									{{ optional($req->attendanceLog)->scanned_at?->format('h:i A') ?? 'a punch' }}
									@if(optional($req->attendanceLog)->isVoided())
										<span class="badge bg-danger ms-1">voided</span>
									@endif
								</div>
							@else
								<div class="small text-muted mt-1"><i class="ti ti-plus me-1"></i>no punch recorded</div>
							@endif
						</td>
						<td><small>{{ $req->reason }}</small></td>
						<td><small class="text-muted">{{ $req->created_at->diffForHumans() }}</small></td>
						<td>
							<span class="badge bg-{{ \App\Models\AttendanceRegularisation::STATUS_BADGES[$req->status] ?? 'secondary' }}">
								{{ \App\Models\AttendanceRegularisation::STATUSES[$req->status] ?? $req->status }}
							</span>
							@if($req->decided_by_label)
								<div class="small text-muted mt-1">by {{ $req->decided_by_label }}</div>
							@endif
							@if($req->decision_note)
								<div class="small text-muted">{{ $req->decision_note }}</div>
							@endif
						</td>
						<td class="text-end">
							@if($req->isPending())
							<button type="button" class="btn btn-sm btn-success js-decide"
								data-url="{{ route('attendance.regularisations.approve', $req) }}"
								data-verb="Approve"
								data-who="{{ $req->employee->full_name ?? 'Unknown' }}"
								data-what="{{ strtoupper($req->type) }} at {{ $req->requested_at->format('d M Y, h:i A') }}"
								data-warn="{{ $req->challengesAPunch() ? '1' : '0' }}">Approve</button>
							<button type="button" class="btn btn-sm btn-outline-danger js-decide"
								data-url="{{ route('attendance.regularisations.reject', $req) }}"
								data-verb="Reject"
								data-who="{{ $req->employee->full_name ?? 'Unknown' }}"
								data-what="{{ strtoupper($req->type) }} at {{ $req->requested_at->format('d M Y, h:i A') }}"
								data-warn="0">Reject</button>
							@elseif($req->created_log_id)
								<small class="text-muted">punch #{{ $req->created_log_id }} recorded</small>
							@else
								<small class="text-muted">—</small>
							@endif
						</td>
					</tr>
					@empty
					<tr>
						<td colspan="6" class="text-center text-muted py-4">
							Nothing here. {{ request('status') ? 'No requests with this status.' : 'No corrections are waiting on you.' }}
						</td>
					</tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $requests->links() }}
	</div>
</div>

{{-- One modal for both verbs; the action and wording are swapped in from the
	 clicked button so there is not a pair of modals per row in the DOM. --}}
<div class="modal fade" id="decideModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form method="POST" action="" class="modal-content" id="decideForm">
			@csrf
			<div class="modal-header">
				<h5 class="modal-title" id="decideTitle">Decide</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="mb-2"><strong id="decideWho"></strong><br><span class="text-muted" id="decideWhat"></span></p>
				<div class="alert alert-warning py-2 d-none" id="decideWarn">
					Approving this will void the existing punch and record the corrected one in its place.
					Both stay on the record.
				</div>
				<label class="form-label" for="decision_note">Note <span class="text-muted">(optional)</span></label>
				<textarea name="decision_note" id="decision_note" class="form-control" rows="2" maxlength="500"></textarea>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary" id="decideSubmit">Confirm</button>
			</div>
		</form>
	</div>
</div>

@push('scripts')
<script>
	(function () {
		var modalEl = document.getElementById('decideModal');
		if (!modalEl) return;
		var modal = new bootstrap.Modal(modalEl);

		document.querySelectorAll('.js-decide').forEach(function (button) {
			button.addEventListener('click', function () {
				var approving = button.dataset.verb === 'Approve';

				document.getElementById('decideForm').action = button.dataset.url;
				document.getElementById('decideTitle').textContent = button.dataset.verb + ' correction';
				document.getElementById('decideWho').textContent = button.dataset.who;
				document.getElementById('decideWhat').textContent = button.dataset.what;
				document.getElementById('decision_note').value = '';

				var submit = document.getElementById('decideSubmit');
				submit.textContent = button.dataset.verb;
				submit.className = 'btn ' + (approving ? 'btn-success' : 'btn-danger');

				// Only shown when a punch is actually being struck out.
				document.getElementById('decideWarn')
					.classList.toggle('d-none', !(approving && button.dataset.warn === '1'));

				modal.show();
			});
		});
	})();
</script>
@endpush
@endsection
