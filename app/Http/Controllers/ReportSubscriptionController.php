<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\ReportSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

/**
 * Standing orders for report delivery (A7.12).
 *
 * Gated on export-reports rather than view-reports throughout, including the
 * listing: a subscription is a way to get report data out of the system on a
 * repeating basis, and somebody who may not export by hand must not be able to
 * arrange for it to be posted to an outside address every month instead.
 */
class ReportSubscriptionController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index()
    {
        $subscriptions = ReportSubscription::with('office')
            ->where('company_id', $this->companyId())
            ->orderBy('report_type')
            ->get();

        $offices = Office::where('company_id', $this->companyId())->orderBy('name')->get();

        return view('report-subscriptions.index', compact('subscriptions', 'offices'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['company_id'] = $this->companyId();
        $data['created_by_user_id'] = auth()->id();

        ReportSubscription::create($data);

        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, ReportSubscription $subscription)
    {
        abort_unless($subscription->company_id === $this->companyId(), 403);

        $subscription->update($this->validated($request));

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroy(ReportSubscription $subscription)
    {
        abort_unless($subscription->company_id === $this->companyId(), 403);

        $subscription->delete();

        return back()->with('success', 'Scheduled report removed.');
    }

    /**
     * Send one now, without waiting for its schedule.
     *
     * The point is to find out that a standing order works before trusting it —
     * a monthly payroll report that was configured wrongly otherwise announces
     * itself four weeks later, to the finance team, in front of everyone.
     *
     * Deliberately does not stamp last_sent_at: a test send is not the month's
     * delivery, and treating it as one would skip the real send.
     */
    public function send(ReportSubscription $subscription)
    {
        abort_unless($subscription->company_id === $this->companyId(), 403);

        $exit = Artisan::call('reports:send', ['--subscription' => $subscription->id]);

        return $exit === 0
            ? back()->with('success', 'Report sent to ' . implode(', ', $subscription->recipients) . '.')
            : back()->with('error', 'The report could not be sent. Check the mail configuration and the log.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'report_type' => ['required', Rule::in(array_keys(ReportSubscription::REPORTS))],
            'frequency'   => ['required', Rule::in(array_keys(ReportSubscription::FREQUENCIES))],
            'format'      => ['required', Rule::in(array_keys(ReportSubscription::FORMATS))],
            'office_id'   => [
                'nullable',
                // Scoped, so a subscription cannot be pointed at another
                // company's branch by editing the form's hidden value.
                Rule::exists('offices', 'id')->where('company_id', $this->companyId()),
            ],
            'recipients'  => 'required|string|max:1000',
            'is_active'   => 'nullable|boolean',
        ]);

        // Typed as one field because that is how people have the list — pasted
        // out of an address book, separated by whatever they happened to use.
        $recipients = collect(preg_split('/[,;\s]+/', $data['recipients'], -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($email) => trim($email))
            ->unique()
            ->values();

        $bad = $recipients->reject(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));

        if ($recipients->isEmpty() || $bad->isNotEmpty()) {
            // Named rather than a generic "invalid": with six addresses on one
            // line, knowing which one is wrong is the entire message.
            throw \Illuminate\Validation\ValidationException::withMessages([
                'recipients' => $bad->isEmpty()
                    ? 'Enter at least one email address.'
                    : 'Not a valid email address: ' . $bad->implode(', '),
            ]);
        }

        $data['recipients'] = $recipients->all();
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
