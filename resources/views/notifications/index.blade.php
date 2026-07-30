{{--
	The notification centre.

	One screen for both audiences, so it picks its shell from the reader's role:
	a manager reaching it from the portal keeps the portal chrome, an HR user
	keeps the dashboard. The alternative — two near-identical views — would drift
	the moment one of them was edited.
--}}
@extends(auth()->user()->hasAnyRole(['admin', 'hr']) ? 'layouts.app' : 'layouts.employee')
@section('title', 'Notifications')

@section('content')
<div class="{{ auth()->user()->hasAnyRole(['admin', 'hr']) ? 'content' : 'emp-wrap py-3' }}">

	<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
		<div>
			<h5 class="mb-1">Notifications</h5>
			<p class="text-muted mb-0 fs-13">
				@if ($unread)
					You have {{ $unread }} unread {{ Str::plural('notification', $unread) }}.
				@else
					You are all caught up.
				@endif
			</p>
		</div>

		@if ($unread)
			<form method="POST" action="{{ route('notifications.read-all') }}">
				@csrf
				<button type="submit" class="btn btn-outline-primary btn-sm">
					<i class="ti ti-checks me-1"></i>Mark all read
				</button>
			</form>
		@endif
	</div>

	@if (session('success'))
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			{{ session('success') }}
			<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
		</div>
	@endif

	<div class="card">
		<div class="list-group list-group-flush">
			@forelse ($notifications as $note)
				{{-- Unread is carried by a tinted row rather than only a dot: the
				     distinction has to survive being printed or read by someone
				     who cannot pick a small colour cue out of a list. --}}
				<a href="{{ route('notifications.show', $note->id) }}"
				   class="list-group-item list-group-item-action d-flex gap-3 py-3 {{ $note->read_at ? '' : 'bg-light' }}">

					<span class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle flex-shrink-0">
						<i class="ti {{ str_contains($note->data['type'] ?? '', 'submitted') ? 'ti-calendar-plus' : 'ti-calendar-check' }}"></i>
					</span>

					<div class="flex-grow-1">
						<div class="d-flex justify-content-between align-items-start gap-2">
							<span class="fw-semibold">{{ $note->data['title'] ?? 'Notification' }}</span>
							<span class="text-muted fs-12 text-nowrap">{{ $note->created_at->diffForHumans() }}</span>
						</div>
						<div class="text-muted fs-13">{{ $note->data['body'] ?? '' }}</div>
					</div>

					@unless ($note->read_at)
						<span class="badge bg-primary align-self-center">New</span>
					@endunless
				</a>
			@empty
				<div class="text-center py-5 text-muted">
					<i class="ti ti-bell-off fs-2 d-block mb-2"></i>
					<p class="mb-0">No notifications yet.</p>
					<p class="fs-13 mb-0">Leave requests and approvals will show up here.</p>
				</div>
			@endforelse
		</div>

		@if ($notifications->hasPages())
			<div class="card-footer">
				{{ $notifications->links() }}
			</div>
		@endif
	</div>
</div>
@endsection
