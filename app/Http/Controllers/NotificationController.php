<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\CompanyAnnouncement;
use App\Notifications\LeaveRequestSubmitted;
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
            ->paginate($this->perPage('notifications'));

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

        return redirect($this->destination($request->user(), $notification->data));
    }

    /**
     * Where a notification points, for the person opening it.
     *
     * Two types are addressed to people with different screens, and their
     * stored url was once the same for everyone — HR sent to the manager's
     * inbox, staff to the announcement editor, both 403s. Those rows still
     * exist, so the destination is worked out again here rather than trusted.
     */
    private function destination(User $user, array $data): string
    {
        return match ($data['type'] ?? null) {
            'leave.submitted' => LeaveRequestSubmitted::urlFor($user),
            'announcement'    => CompanyAnnouncement::urlFor($user),
            default           => $data['url'] ?? route('notifications.index'),
        };
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
