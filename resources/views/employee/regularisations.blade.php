@extends('layouts.employee')
@section('title', 'Fix My Attendance')

@section('content')

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card mb-3">
	<div class="card-header">
		<h5 class="mb-0"><i class="ti ti-edit me-1"></i>Ask for a correction</h5>
	</div>
	<div class="card-body">
		<p class="text-muted small">
			Forgot to check out, or a reading looks wrong? Tell HR what it should say.
			Nothing changes until they approve it.
		</p>

		<form method="POST" action="{{ route('employee.regularisations.store') }}">
			@csrf

			<div class="mb-3">
				<label class="form-label" for="attendance_log_id">What is wrong</label>
				<select name="attendance_log_id" id="attendance_log_id" class="form-select">
					<option value="">A punch is missing entirely</option>
					@foreach($recentPunches as $punch)
						<option value="{{ $punch->id }}" {{ old('attendance_log_id') == $punch->id ? 'selected' : '' }}>
							{{ strtoupper($punch->type) }} — {{ $punch->scanned_at->format('D d M Y, h:i A') }} ({{ $punch->status }})
						</option>
					@endforeach
				</select>
				<div class="form-text">Pick the punch that is wrong, or leave it on "missing" if none was recorded.</div>
			</div>

			<div class="row">
				<div class="col-sm-4 mb-3">
					<label class="form-label" for="type">Should be</label>
					<select name="type" id="type" class="form-select" required>
						<option value="in" {{ old('type') === 'in' ? 'selected' : '' }}>Check in</option>
						<option value="out" {{ old('type') === 'out' ? 'selected' : '' }}>Check out</option>
					</select>
				</div>
				<div class="col-sm-8 mb-3">
					<label class="form-label" for="requested_at">Correct date &amp; time</label>
					<input type="datetime-local" name="requested_at" id="requested_at" class="form-control"
						value="{{ old('requested_at') }}" required>
				</div>
			</div>

			<div class="mb-3">
				<label class="form-label" for="reason">Why</label>
				<textarea name="reason" id="reason" class="form-control" rows="2" minlength="5" maxlength="500"
					placeholder="e.g. I left at 6pm but forgot to press check out" required>{{ old('reason') }}</textarea>
			</div>

			<button type="submit" class="btn btn-primary">Send to HR</button>
		</form>
	</div>
</div>

<div class="card">
	<div class="card-header">
		<h5 class="mb-0"><i class="ti ti-history me-1"></i>My requests</h5>
	</div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table align-middle">
				<thead>
					<tr>
						<th>Asked for</th>
						<th>Reason</th>
						<th>Status</th>
						<th class="text-end"></th>
					</tr>
				</thead>
				<tbody>
					@forelse($requests as $req)
					<tr>
						<td>
							<span class="badge bg-{{ $req->type === 'in' ? 'success' : 'secondary' }}">{{ strtoupper($req->type) }}</span>
							{{ $req->requested_at->format('d M Y, h:i A') }}
							@if($req->challengesAPunch())
								<div class="small text-muted">replacing an existing punch</div>
							@endif
						</td>
						<td><small>{{ $req->reason }}</small></td>
						<td>
							<span class="badge bg-{{ \App\Models\AttendanceRegularisation::STATUS_BADGES[$req->status] ?? 'secondary' }}">
								{{ \App\Models\AttendanceRegularisation::STATUSES[$req->status] ?? $req->status }}
							</span>
							@if($req->decision_note)
								<div class="small text-muted mt-1">{{ $req->decision_note }}</div>
							@endif
						</td>
						<td class="text-end">
							@if($req->isPending())
							<form method="POST" action="{{ route('employee.regularisations.cancel', $req) }}">
								@csrf
								<button type="submit" class="btn btn-sm btn-outline-secondary">Withdraw</button>
							</form>
							@endif
						</td>
					</tr>
					@empty
					<tr><td colspan="4" class="text-center text-muted py-4">You have not asked for any corrections.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $requests->links() }}
	</div>
</div>
@endsection
