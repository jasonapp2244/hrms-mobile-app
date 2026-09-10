@extends('layouts.app')
@section('title','Announcements')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
	<div class="my-auto mb-2"><h2 class="mb-1">Announcements</h2>
		<nav><ol class="breadcrumb mb-0">
			<li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
			<li class="breadcrumb-item active">Announcements</li>
		</ol></nav></div>
	<div class="d-flex align-items-center flex-wrap gap-2">
		<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="ti ti-speakerphone me-1"></i>New Announcement</button>
	</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
	<i class="ti ti-info-circle me-1"></i>
	Sending writes the message into every recipient's notification list <strong>and pushes it to their
	phone</strong>. Neither can be recalled, so an announcement <strong>cannot be edited or deleted once
	sent</strong> &mdash; send a follow-up instead. Save it as a draft first if anybody else needs to read it.
	<div class="mt-1 small">The text goes out exactly as typed, in whatever language you write it. It is not translated.</div>
</div>

<div class="card">
	<div class="card-header"><h5 class="mb-0">Register</h5></div>
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover align-middle">
				<thead>
					<tr>
						<th>Announcement</th>
						<th>Audience</th>
						<th>Status</th>
						<th>By</th>
						<th class="text-end">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse($announcements as $a)
					<tr>
						<td>
							<div class="fw-semibold">{{ $a->title }}</div>
							<div class="text-muted small">{{ Str::limit($a->body, 120) }}</div>
						</td>
						<td>{{ $a->audienceLabel() }}</td>
						<td>
							@if($a->is_published)
								<span class="badge bg-success">Sent</span>
								<div class="text-muted small mt-1">
									{{ $a->published_at->format('M j, Y H:i') }}
									&middot; {{ $a->recipients_count }} {{ $a->recipients_count === 1 ? 'person' : 'people' }}
								</div>
							@else
								<span class="badge bg-warning text-dark">Draft</span>
								<div class="text-muted small mt-1">Nothing sent</div>
							@endif
						</td>
						<td>{{ $a->author_label ?? '—' }}</td>
						<td class="text-end">
							@if($a->is_published)
								<span class="text-muted small">Sent &mdash; no longer editable</span>
							@else
								<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal{{ $a->id }}"><i class="ti ti-edit"></i></button>
								<form action="{{ route('announcements.publish', $a) }}" method="POST" class="d-inline" onsubmit="return confirm('Send this to {{ $a->audienceLabel() }}? It cannot be recalled.');">
									@csrf
									<button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-send me-1"></i>Send</button>
								</form>
								<form action="{{ route('announcements.destroy', $a) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this draft?');">
									@csrf
									@method('DELETE')
									<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
								</form>
							@endif
						</td>
					</tr>

					@unless($a->is_published)
					<!-- Edit Modal -->
					<div class="modal fade" id="editModal{{ $a->id }}" tabindex="-1" aria-hidden="true">
						<div class="modal-dialog modal-lg">
							<div class="modal-content">
								<form action="{{ route('announcements.update', $a) }}" method="POST">
									@csrf
									@method('PUT')
									<div class="modal-header">
										<h5 class="modal-title">Edit Draft</h5>
										<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
									</div>
									<div class="modal-body">
										@include('announcements.form', ['a' => $a, 'uid' => 'e' . $a->id])
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
										<button type="submit" class="btn btn-primary">Save Draft</button>
									</div>
								</form>
							</div>
						</div>
					</div>
					@endunless
					@empty
					<tr><td colspan="5" class="text-center text-muted">Nothing announced yet.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
		{{ $announcements->links() }}
	</div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<form action="{{ route('announcements.store') }}" method="POST">
				@csrf
				<div class="modal-header">
					<h5 class="modal-title">New Announcement</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					@include('announcements.form', ['a' => null, 'uid' => 'new'])
					<div class="form-check mt-3">
						{{-- Unticked by default: the safe outcome of a mis-click is a draft. --}}
						<input type="hidden" name="publish_now" value="0">
						<input class="form-check-input" type="checkbox" name="publish_now" value="1" id="publishNow">
						<label class="form-check-label" for="publishNow">
							<strong>Send it straight away.</strong> Leave this off to save a draft and send it later.
						</label>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Save</button>
				</div>
			</form>
		</div>
	</div>
</div>
@endsection
