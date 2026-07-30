<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\Office;
use Illuminate\Http\Request;

/**
 * The company holiday calendar.
 *
 * Holidays are not decoration: a holiday inside a leave range is not charged to
 * the employee's balance, and a holiday is never counted as an absence. Until
 * now the table could only be filled by hand in the database.
 */
class HolidayController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $year = (int) $request->input('year', date('Y'));

        $holidays = Holiday::where('company_id', $companyId)
            ->get()
            // Recurring holidays are stored once against an arbitrary year, so
            // they are projected onto the year being viewed rather than filtered
            // out of it. Paired rather than written onto the model, so nothing
            // carries a column that does not exist.
            ->map(fn (Holiday $h) => [
                'holiday'     => $h,
                'observed_on' => $h->is_recurring ? $h->date->copy()->setYear($year) : $h->date,
            ])
            ->filter(fn (array $row) => $row['observed_on']->year === $year)
            ->sortBy(fn (array $row) => $row['observed_on']->toDateString())
            ->values();

        // Year picker range: whatever is on record, plus a couple ahead so next
        // year can be entered before it starts.
        $years = range((int) date('Y') - 2, (int) date('Y') + 2);

        return view('holidays.index', compact('holidays', 'year', 'years'));
    }

    public function store(Request $request)
    {
        $data = $this->validateHoliday($request);
        $data['company_id'] = $this->companyId();
        Holiday::create($data);

        return back()->with('success', 'Holiday added.');
    }

    public function update(Request $request, Holiday $holiday)
    {
        abort_unless($holiday->company_id === $this->companyId(), 403);
        $holiday->update($this->validateHoliday($request));

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(Holiday $holiday)
    {
        abort_unless($holiday->company_id === $this->companyId(), 403);
        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }

    protected function validateHoliday(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'date' => 'required|date',
        ]);

        $data['is_recurring'] = $request->boolean('is_recurring');

        return $data;
    }
}
