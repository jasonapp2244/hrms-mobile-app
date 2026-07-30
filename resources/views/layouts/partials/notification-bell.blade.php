{{--
	The notification bell.

	Shared by the staff header and the employee portal so both read the same
	list — a manager who is also an employee has one inbox, not two.

	Only the five most recent are loaded for the dropdown; the rest live on the
	notification centre. A person with a thousand unread messages should not
	make every page render a thousand rows.
--}}
@php
	$unreadNotifications = auth()->user()?->unreadNotifications()->latest()->limit(5)->get() ?? collect();
	$unreadCount = auth()->user()?->unreadNotifications()->count() ?? 0;
@endphp

<div class="dropdown me-2">
	<a href="javascript:void(0);" class="btn btn-menubar position-relative" data-bs-toggle="dropdown" aria-label="Notifications">
		<i class="ti ti-bell"></i>
		@if ($unreadCount)
			<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;">
				{{ $unreadCount > 9 ? '9+' : $unreadCount }}
				<span class="visually-hidden">unread notifications</span>
			</span>
		@endif
	</a>

	<div class="dropdown-menu dropdown-menu-end shadow-none p-0" style="min-width:320px;max-width:360px;">
		<div class="card mb-0 border-0">
			<div class="card-header d-flex align-items-center justify-content-between py-2">
				<h6 class="mb-0">Notifications</h6>
				@if ($unreadCount)
					<form method="POST" action="{{ route('notifications.read-all') }}" class="mb-0">
						@csrf
						<button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">Mark all read</button>
					</form>
				@endif
			</div>

			<div class="card-body p-0" style="max-height:320px;overflow-y:auto;">
				@forelse ($unreadNotifications as $note)
					<a href="{{ route('notifications.show', $note->id) }}"
					   class="dropdown-item d-block p-2 border-bottom text-wrap">
						<div class="fw-semibold fs-13 mb-1">{{ $note->data['title'] ?? 'Notification' }}</div>
						<div class="fs-12 text-muted mb-1">{{ $note->data['body'] ?? '' }}</div>
						<div class="fs-11 text-muted">{{ $note->created_at->diffForHumans() }}</div>
					</a>
				@empty
					<div class="p-3 text-center text-muted fs-13">
						<i class="ti ti-bell-off d-block mb-1"></i>
						Nothing new.
					</div>
				@endforelse
			</div>

			<div class="card-footer p-2 text-center">
				<a href="{{ route('notifications.index') }}" class="fs-13 text-decoration-none">See all notifications</a>
			</div>
		</div>
	</div>
</div>
