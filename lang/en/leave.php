<?php

/*
|--------------------------------------------------------------------------
| Leave: what a request is, and what it is waiting on (C1.18)
|--------------------------------------------------------------------------
|
| **The leave *type* is not here.** "Annual Leave", "Unpaid" and the rest are
| rows in `leave_types`, configured per company — data, not vocabulary. A
| company that wants them in Spanish renames them, which is the only answer that
| also works for a type nobody here has thought of.
|
| Shared with the web dashboard, which resolves them under the default locale.
|
*/

return [

    'status' => [
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ],

    // "Pending" alone does not say whose desk it is on, which is the whole
    // reason the API sends a stage as well as a status.
    'stage' => [
        'awaiting_manager' => 'Awaiting Manager',
        'awaiting_hr'      => 'Awaiting HR',
    ],

];
