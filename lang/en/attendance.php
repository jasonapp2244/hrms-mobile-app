<?php

/*
|--------------------------------------------------------------------------
| Clocking in, breaks, and the reasons a punch is refused (C1.18)
|--------------------------------------------------------------------------
|
| **Shared with the web dashboard.** `AttendanceService` raises these and both
| halves show them, which is the point: one set of punch rules and one set of
| words for them. A web request resolves them under the default locale and gets
| exactly the English it always did.
|
*/

return [

    // --- The result of a punch --------------------------------------------
    'clocked_in'  => 'You clocked IN at :time.',
    'clocked_out' => 'You clocked OUT at :time.',

    'break_started' => 'Break started at :time. Your worked time pauses until you return.',
    'break_ended'   => 'Break ended at :time. Welcome back.',

    // --- Refusals ----------------------------------------------------------
    'duplicate_scan' => 'Already recorded moments ago. Please wait a minute.',
    'no_office'      => 'No office is set up for your company yet. Please contact HR.',
    'not_clocked_in' => 'You need to be checked in before starting a break.',

    // Names the distance on purpose: "outside the area" with no number is a
    // refusal somebody cannot act on.
    'outside_geofence' => 'You appear to be :distance from :office, which is outside the :radius'
        . 'm check-in area. Move closer, or ask HR to record this punch for you.',

    // --- Punches that arrive late, from the offline queue (B2.4) -----------
    'punch_in_future' => 'That punch is dated in the future. Check the date and time on this device.',
    'punch_too_old'   => 'That punch is more than :hours hours old. Ask for a correction instead, '
        . 'so it can be checked and recorded properly.',

    // --- What a punch is called -------------------------------------------
    'type' => [
        'in'          => 'Checked in',
        'out'         => 'Checked out',
        'break_start' => 'Break started',
        'break_end'   => 'Back from break',
    ],


    // B2.7. Short enough to sit in a badge. Each one is a fact the handset
    // reported about itself, not an accusation — see AttendanceLog::looksTampered.
    'flag_mocked'   => 'Mock location',
    'flag_rooted'   => 'Rooted device',
    'flag_emulator' => 'Emulator',

];
