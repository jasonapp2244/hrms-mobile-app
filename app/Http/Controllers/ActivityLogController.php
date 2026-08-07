<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The security trail (A1.8).
 *
 * Read-only by construction — the model refuses updates and deletes — so there
 * is no store, update or destroy here and there never should be.
 *
 * Gated on manage-settings, which is admin-only. HR can see attendance and
 * leave; who tried to sign in as whom is a different question and belongs with
 * whoever owns the accounts.
 */
class ActivityLogController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $logs = ActivityLog::with('user')
            // Failed sign-ins have no company — nobody was identified — so they
            // are pulled in alongside rather than filtered out. Leaving them out
            // would hide the entries somebody comes to this screen to find.
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('actor_label', 'like', '%' . $request->q . '%')
                ->orWhere('ip_address', 'like', '%' . $request->q . '%')))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('activity.index', [
            'logs'   => $logs,
            'users'  => User::where('company_id', $companyId)->orderBy('name')->get(),
            'events' => ActivityLog::EVENTS,
        ]);
    }
}
