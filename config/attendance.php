<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Overtime (A4.14)
    |--------------------------------------------------------------------------
    |
    | Overtime is worked time beyond what the day was scheduled for. The shift
    | says how long that is — end minus start, less the unpaid break — and any
    | minutes past it, above the threshold below, are overtime.
    |
    */

    'overtime' => [

        /*
         * Minutes past the scheduled end that do not count.
         *
         * Without this, every employee who takes two minutes to pack up earns
         * overtime every single day, and the report becomes a list of everyone.
         * It mirrors late_grace_minutes at the other end of the day: the shift
         * already forgives a few minutes arriving, and forgiving none leaving
         * would be a strange pair of rules to explain to staff.
         */
        'threshold_minutes' => env('OVERTIME_THRESHOLD_MINUTES', 15),

        /*
         * The most overtime one day can report, as a sanity bound.
         *
         * Its real job is catching a forgotten check-out that a later manual
         * entry closed at an implausible hour: without a ceiling, one bad punch
         * turns into a 14-hour overtime claim sitting in a payroll export. Null
         * disables the cap for sites that genuinely run very long days.
         */
        'daily_cap_minutes' => env('OVERTIME_DAILY_CAP_MINUTES', 720),

        /*
         * Whether time worked on a day nobody was rostered counts as overtime.
         *
         * True is the ordinary answer — somebody who comes in on their day off
         * worked every minute of it beyond schedule. Turn it off only where
         * unrostered attendance is handled some other way.
         */
        'count_unrostered_days' => env('OVERTIME_COUNT_UNROSTERED', true),
    ],

];
