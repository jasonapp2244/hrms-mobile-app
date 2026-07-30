<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The notification centre, for any signed-in user.
 *
 * Not permission-gated: everything here belongs to the person reading it, and
 * Laravel scopes the relation to them. There is no notification anybody could
 * see that was not addressed to them.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate(20);

        return view('notifications.index', [
            'notifications' => $notifications,
            'unread'        => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Open one: mark it read, then go where it points.
     *
     * Reading and acting are the same gesture — nobody marks a notification
     * read and then separately goes to look at it — so the click does both.
     */
    public function show(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        return redirect($notification->data['url'] ?? route('notifications.index'));
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
