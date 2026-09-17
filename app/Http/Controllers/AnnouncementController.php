<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Office;
use App\Notifications\CompanyAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * HR announcements and broadcasts (B5.5).
 *
 * Writing is a two-step gesture on purpose. Everything else in this dashboard
 * saves and is done; this one **saves as a draft and then asks again**, because
 * the second click writes a row into every employee's notification table and
 * wakes every registered handset, and there is no way back from either. A
 * single "Send" button beside a textarea is one slip from a company-wide
 * message with a half-finished sentence in it.
 *
 * The delivery itself is an ordinary notification, which is the whole reason
 * this needed no mobile release: `announcement` lands in the same history
 * endpoint and the same bell as everything else.
 */
class AnnouncementController extends Controller
{
    protected function scoped(Announcement $announcement): Announcement
    {
        abort_unless($announcement->company_id === $this->companyId(), 403);

        return $announcement;
    }

    public function index()
    {
        $companyId = $this->companyId();

        $announcements = Announcement::with(['author', 'department', 'office'])
            ->where('company_id', $companyId)
            // Drafts first — they are the ones with something still to do —
            // then published, newest first.
            ->orderByRaw('published_at is null desc')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->perPage('announcements'));

        return view('announcements.index', [
            'announcements' => $announcements,
            'departments'   => Department::where('company_id', $companyId)->orderBy('name')->get(),
            'offices'       => Office::where('company_id', $companyId)->orderBy('name')->get(),
            'audiences'     => Announcement::AUDIENCES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $announcement = Announcement::create($data + [
            'company_id'   => $this->companyId(),
            'created_by'   => auth()->id(),
            'author_label' => auth()->user()->name,
        ]);

        // Straight through when the author asked for it, so the common case is
        // still one form and one confirm rather than two round trips.
        if ($request->boolean('publish_now')) {
            return $this->publish($announcement);
        }

        return back()->with('success', 'Draft saved. Nothing has been sent yet.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->scoped($announcement);

        // The model throws on a published row; catching it here turns a 500
        // into the sentence that explains why.
        if ($announcement->is_published) {
            return back()->with('error',
                'That announcement has already been sent, so it can no longer be edited. Send a follow-up instead.');
        }

        $announcement->update($this->validated($request));

        return back()->with('success', 'Draft updated.');
    }

    /**
     * Send it.
     *
     * The audience is resolved here and now, and the count is written to the
     * row — see the migration for why it is never recomputed.
     */
    public function publish(Announcement $announcement)
    {
        $this->scoped($announcement);

        if ($announcement->is_published) {
            return back()->with('error', 'That announcement has already been sent.');
        }

        $recipients = $announcement->recipients();

        if ($recipients->isEmpty()) {
            return back()->with('error',
                'Nobody is in that audience, so there is nothing to send. Check the department or office still has active staff in it.');
        }

        Notification::send($recipients, CompanyAnnouncement::for($announcement));

        // `saveQuietly` past the model's own guard: this is the one write that
        // is allowed to set `published_at`.
        $announcement->forceFill([
            'published_at'     => now(),
            'recipients_count' => $recipients->count(),
        ])->saveQuietly();

        ActivityLog::record(
            event: ActivityLog::SETTINGS_CHANGED,
            description: sprintf(
                'Announcement "%s" sent to %d %s',
                $announcement->title,
                $recipients->count(),
                $recipients->count() === 1 ? 'person' : 'people',
            ),
            subject: $announcement,
        );

        return back()->with('success', sprintf(
            'Sent to %d %s.',
            $recipients->count(),
            $recipients->count() === 1 ? 'person' : 'people',
        ));
    }

    public function destroy(Announcement $announcement)
    {
        $this->scoped($announcement);

        if ($announcement->is_published) {
            return back()->with('error',
                'That announcement has already been sent. Deleting the record here would not unsend it, so the register keeps it.');
        }

        $announcement->delete();

        return back()->with('success', 'Draft deleted.');
    }

    /**
     * The audience fields are required only by the audience that uses them.
     *
     * `required_if` rather than always-nullable: an announcement saved as
     * "one department" with no department picked would go to nobody and say
     * nothing about why.
     */
    protected function validated(Request $request): array
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'title'    => 'required|string|max:150',
            'body'     => 'required|string|max:5000',
            'audience' => ['required', Rule::in(array_keys(Announcement::AUDIENCES))],
            'department_id' => [
                'nullable',
                'required_if:audience,' . Announcement::DEPARTMENT,
                Rule::exists('departments', 'id')->where('company_id', $companyId),
            ],
            'office_id' => [
                'nullable',
                'required_if:audience,' . Announcement::OFFICE,
                Rule::exists('offices', 'id')->where('company_id', $companyId),
            ],
        ], [
            'department_id.required_if' => 'Choose which department this goes to.',
            'office_id.required_if'     => 'Choose which office this goes to.',
        ]);

        // Clear the field the chosen audience does not use, so a row cannot
        // carry an office id that nothing reads and everything displays.
        $data['department_id'] = $data['audience'] === Announcement::DEPARTMENT ? $data['department_id'] : null;
        $data['office_id']     = $data['audience'] === Announcement::OFFICE ? $data['office_id'] : null;

        return $data;
    }
}
