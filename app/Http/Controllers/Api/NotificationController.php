<?php

namespace App\Http\Controllers\Api;

use App\Support\AppRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The notification history the app never had (B5.6).
 *
 * Everything here is already in the `notifications` table — the web dashboard
 * has read it since A9 shipped — so this is the API catching up rather than a
 * new feature underneath. Until now a push that arrived while the phone was in
 * a locker was simply gone: the OS notification is swiped away and the app kept
 * nothing.
 *
 * **No permission gate, deliberately.** A notification belongs to the person it
 * was addressed to, and Laravel's relation scopes it to them; there is nothing
 * here anybody could reach that was not sent to them. This is the one
 * employee-facing endpoint that does *not* go through [employee()], because a
 * notification is addressed to a **user** — an HR account with no employee
 * record still gets document-expiry warnings, and refusing to show them would
 * be a bug rather than a boundary.
 */
class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $page = $user->notifications()->paginate($this->perPage('notifications'));

        return $this->ok([
            'notifications' => collect($page->items())
                ->map(fn (DatabaseNotification $n) => $this->present($n))
                ->all(),
            // Counted separately from the page: the badge is about everything
            // unread, not about what happens to be on screen.
            'unread' => $user->unreadNotifications()->count(),
            'meta'   => $this->pageMeta($page),
        ]);
    }

    /**
     * Mark one read.
     *
     * Separate from opening it, unlike the web screen, where clicking a row
     * both marks it and navigates. On a phone the list *is* the destination for
     * most of these — the body is the whole message — so reading and going
     * somewhere are genuinely different gestures.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        // Not a 404. The app may be catching up on a tap made offline, and by
        // then the row can be gone; "it is not unread any more" is true either
        // way, and an error would leave the screen showing a badge it cannot
        // clear.
        $notification?->markAsRead();

        return $this->ok(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->ok(['unread' => 0]);
    }

    /**
     * One row, as the app reads it.
     *
     * `data` is whatever the notification class put in `toDatabase()`, which
     * differs per class and includes a **web** `url` the app cannot use. Only
     * the four keys every class agrees on are published, plus the route, so a
     * new notification type needs no client change to appear here.
     */
    protected function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $type = is_string($data['type'] ?? null) ? $data['type'] : null;

        return [
            'id'    => $notification->id,
            'type'  => $type,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : 'Notification',
            'body'  => is_string($data['body'] ?? null) ? $data['body'] : null,
            // Derived rather than stored: `toDatabase()` has never recorded a
            // route, so a row written before B5.6 would otherwise have none.
            'route'      => AppRoute::forType($type),
            'read_at'    => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
