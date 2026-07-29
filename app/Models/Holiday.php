<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = [
        'company_id', 'name', 'date', 'is_recurring',
    ];

    protected $casts = [
        'date'         => 'date',
        'is_recurring' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Every holiday date falling inside a range, as 'Y-m-d' strings.
     *
     * Recurring holidays are stored once against an arbitrary year, so they are
     * projected onto each year the range spans rather than matched literally.
     */
    public static function datesBetween(int $companyId, string $from, string $to): array
    {
        $start = \Carbon\Carbon::parse($from);
        $end   = \Carbon\Carbon::parse($to);

        $dates = [];

        foreach (static::where('company_id', $companyId)->get() as $holiday) {
            if (! $holiday->is_recurring) {
                if ($holiday->date->betweenIncluded($start, $end)) {
                    $dates[] = $holiday->date->toDateString();
                }
                continue;
            }

            // Project the day/month onto every year the range touches.
            foreach (range($start->year, $end->year) as $year) {
                $projected = $holiday->date->copy()->setYear($year);
                if ($projected->betweenIncluded($start, $end)) {
                    $dates[] = $projected->toDateString();
                }
            }
        }

        return array_values(array_unique($dates));
    }
}
