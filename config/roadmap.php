<?php

/*
|--------------------------------------------------------------------------
| Delivery phases
|--------------------------------------------------------------------------
|
| The Settings screen renders this list, and it is the first thing a client
| looks at to judge progress — so it lives here rather than as hand-written
| markup in a Blade file. The old panel was hard-coded and drifted badly: it
| still advertised Leave, Shift/Schedule and the mobile app as "coming soon"
| months after all three shipped, while the sidebar beside it linked straight
| into them.
|
| This is the web dashboard's own record. The mobile app phases, the deployment
| phase and the parked AI phase were taken out deliberately: the panel is read
| by people using the dashboard, and work that is either not theirs or not
| happening reads as an unfinished product rather than as a plan.
|
| Each phase maps to sections of `Feature-List_Web-and-App.md`, which stays the
| detailed record — including the mobile app and everything parked. A phase is
| 'delivered' when its module works end to end, not when every row under it is
| ticked: depth keeps arriving after a module is usable, and the note on each
| phase says so where it matters.
|
| Statuses: delivered | active | planned. At most one phase should be 'active';
| every phase here is currently delivered.
|
*/

return [

    'phases' => [
        [
            'no'     => 1,
            'title'  => 'Foundation',
            'detail' => 'Sign-in for all four roles, company & offices, departments, designations, employees with bulk import',
            'status' => 'delivered',
        ],
        [
            'no'     => 2,
            'title'  => 'Attendance',
            'detail' => 'One-tap check in/out, server-authoritative times, auto on-time/late/early-leave, append-only log with audit events',
            'status' => 'delivered',
        ],
        [
            'no'     => 3,
            'title'  => 'Leave management',
            'detail' => 'Leave types, balances, two-step approval chain, holiday calendar, weekend- and holiday-aware',
            'status' => 'delivered',
        ],
        [
            'no'     => 4,
            'title'  => 'Shifts & schedule',
            'detail' => 'Per-department shifts, per-employee overrides, night and rotating patterns, weekly roster with draft/publish, shift swaps',
            'status' => 'delivered',
        ],
        [
            'no'     => 5,
            'title'  => 'Attendance depth',
            'detail' => 'Manual correction by HR with an audit reason, employee regularisation requests, overtime, break punches',
            'status' => 'delivered',
        ],
        [
            'no'     => 6,
            'title'  => 'Reporting & payroll',
            'detail' => 'Payroll-ready hours export, leave reports, scheduled email delivery, and a builder for the reports nobody anticipated',
            'status' => 'delivered',
        ],
        [
            'no'     => 7,
            'title'  => 'Security & policy',
            'detail' => 'Two-factor sign-in, a login audit trail, session timeout, rate-limited sign-in, the working-week editor and geofence enforcement',
            'status' => 'delivered',
        ],
        [
            'no'     => 8,
            'title'  => 'Employee records & leave depth',
            'detail' => 'Photos, a document vault with expiry alerts, emergency contacts, the org chart, the roster export, leave accrual and carry-forward, the leave calendar, the live board and the late-arrival digest',
            'status' => 'delivered',
        ],
        [
            'no'     => 9,
            'title'  => 'Dashboards & checklists',
            'detail' => 'Dashboards per role and per person, week-on-week trends, weekly rollups, schedule-change alerts and on/offboarding checklists',
            'status' => 'delivered',
        ],
    ],

    /* Badge label and colour per status. Kept beside the data so a new status
       cannot be added without deciding how it renders. */
    'styles' => [
        'delivered' => ['label' => 'Delivered', 'class' => 'bg-success'],
        'active'    => ['label' => 'In progress', 'class' => 'bg-warning text-dark'],
        'planned'   => ['label' => 'Planned',   'class' => 'bg-secondary'],
    ],

];
