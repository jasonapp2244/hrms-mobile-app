@extends('layouts.app')
@section('title','Leave Balances')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2"><h2 class="mb-1">Leave Balances</h2>
		<nav><ol class="breadcrumb mb-0">
			<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
			<li class="breadcrumb-item">Leave</li>
			<li class="breadcrumb-item active">Balances</li>
		</ol></nav></div>
	<div class="d-flex align-items-center flex-wrap gap-2">
		<form action="{{ route('leave-balances.generate') }}" method="POST"
			onsubmit="return confirm('Create the missing balance rows for {{ $year }}? Existing balances are not touched.');">
			@csrf
			<input type="hidden" name="year" value="{{ $year }}">
			<button type="submit" class="btn btn-outline-primary"><i class="ti ti-plus me-1"></i>Provision {{ $year }}</button>
		</form>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
	<i class="ti ti-info-circle me-1"></i>
	<strong>Available = Entitled + Carried Forward − Used.</strong>
	Balances are created automatically the first time an employee opens their leave page, and
	<strong>Used</strong> is maintained by the approval flow — edit it only to correct a figure, not
	to grant leave. <em>Provision</em> creates the rows for everyone up front so they can be adjusted
	before anyone books. <em>Recalculate</em> resets Used to the sum of that person's approved
	requests for the year.
</div>

<div class="card">
	<div class="card-body">
		<form method="GET" action="{{ route('leave-balances.index') }}" class="row g-3 align-items-end">
			<div class="col-md-4 col-sm-6">
				<label class="form-label">Employee</label>
				<input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Name or code">
			</div>
			<div class="col-md-3 col-sm-6">
				<label class="form-label">Leave Type</label>
				<select name="leave_type_id" class="form-select">
					<option value="">All</option>
					@foreach($types as $t)
						<option value="{{ $t->id }}" {{ request('leave_type_id') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-2 col-sm-6">
				<label class="form-label">Year</label>
				<select name="year" class="form-select">
					@foreach($years as $y)
						<option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
					@endforeach
				</select>
			</div>
			<div class="col-md-3 col-sm-6 d-flex">
				<button type="submit" class="btn btn-primary me-2"><i class="ti ti-filter me-1"></i>Filter</button>
				<a href="{{ route('leave-balances.index') }}" class="btn btn-light">Clear</a>
			</div>
		</form>
	</div>
</div>

<div class="card mt-3">
	<div class="card-header d-flex align-items-center justify-content-between">
		<h5 class="mb-0">Balances for {{ $year }}</h5>
		<span class="text-muted small">{{ $balances->count() }} of {{ $expected }} possible employee/type rows</span>
	</div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Employee</th>
						<th>Leave Type</th>
						<th class="text-end">Entitled</th>
						<th class="text-end">Carried</th>
						<th class="text-end">Used</th>
						<th class="text-end">Available</th>
						<th class="text-end">Actions</th>
					</tr>
				</thead>
				<tbody>
					@php $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); @endphp
					@forelse($balances as $b)
					<tr>
						<td>
							<strong>{{ $b->employee?->full_name ?? '—' }}</strong>
							<div class="text-muted small">{{ $b->employee?->employee_code }}{{ $b->employee?->department ? ' · ' . $b->employee->department->name : '' }}</div>
						</td>
						<td>
							<span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:{{ $b->leaveType?->color }}"></span>
							{{ $b->leaveType?->name ?? '—' }}
						</td>
						<td class="text-end">{{ $fmt($b->entitled_days) }}</td>
						<td class="text-end">{{ $fmt($b->carried_forward) }}</td>
						<td class="text-end">{{ $fmt($b->used_days) }}</td>
						<td class="text-end">
							<strong class="{{ $b->available < 0 ? 'text-danger' : '' }}">{{ $fmt($b->available) }}</strong>
						</td>
						<td class="text-end text-nowrap">
							<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editBal{{ $b->id }}"><i class="ti ti-edit"></i></button>
							<form action="{{ route('leave-balances.recalculate', $b) }}" method="POST" class="d-inline">
								@csrf
								<button type="submit" class="btn btn-sm btn-outline-secondary" title="Reset Used to the sum of approved requests"><i class="ti ti-refresh"></i></button>
							</form>
						</td>
					</tr>

					<!-- Edit Modal -->
					<div class="modal fade" id="editBal{{ $b->id }}" tabindex="-1" aria-hidden="true">
						<div class="modal-dialog">
							<div class="modal-content">
								<form action="{{ route('leave-balances.update', $b) }}" method="POST">
									@csrf
									@method('PUT')
									<div class="modal-header">
										<h5 class="modal-title">{{ $b->employee?->full_name }} — {{ $b->leaveType?->name }} {{ $b->year }}</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										<div class="mb-3">
											<label class="form-label">Entitled Days <span class="text-danger">*</span></label>
											<input type="number" step="0.5" min="0" max="365" name="entitled_days" class="form-control" value="{{ $fmt($b->entitled_days) }}" required>
											<div class="form-text">This year's allowance. Reduce it for a mid-year joiner.</div>
										</div>
										<div class="mb-3">
											<label class="form-label">Carried Forward <span class="text-danger">*</span></label>
											<input type="number" step="0.5" min="0" max="365" name="carried_forward" class="form-control" value="{{ $fmt($b->carried_forward) }}" required>
											<div class="form-text">Days brought in from last year.</div>
										</div>
										<div class="mb-3">
											<label class="form-label">Used Days <span class="text-danger">*</span></label>
											<input type="number" step="0.5" min="0" max="365" name="used_days" class="form-control" value="{{ $fmt($b->used_days) }}" required>
											<div class="form-text text-warning">
												Maintained automatically as leave is approved and withdrawn. Change it only to
												correct a figure — editing it here does not create or cancel any leave request.
											</div>
										</div>
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
										<button type="submit" class="btn btn-primary">Save Balance</button>
									</div>
								</form>
							</div>
						</div>
					</div>
					@empty
					<tr><td colspan="7" class="text-center text-muted">
						No balances for {{ $year }} yet. They appear as employees open their leave page, or use <strong>Provision {{ $year }}</strong> to create them all now.
					</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
@endsection
