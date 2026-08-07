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
| Each phase maps to sections of `Feature-List_Web-and-App.md`, which stays the
| detailed record. A phase is 'delivered' when its module works end to end, not
| when every row under it is ticked — depth keeps arriving after a module is
| usable, and the note on each phase says so where it matters.
|
| Statuses: delivered | active | planned. Exactly one phase should be 'active'.
|
*/

return [

    'phases' => [
        [
            'no'      => 1,
            'title'   => 'Foundation',
            'detail'  => 'Sign-in for all four roles, company & offices, departments, designations, employees with bulk import',
            'covers'  => 'A1–A3',
            'status'  => 'delivered',
        ],
        [
            'no'      => 2,
            'title'   => 'Attendance',
            'detail'  => 'One-tap check in/out, server-authoritative times, auto on-time/late/early-leave, append-only log with audit events',
            'covers'  => 'A4',
            'status'  => 'delivered',
        ],
        [
            'no'      => 3,
            'title'   => 'Leave management',
            'detail'  => 'Leave types, balances, two-step approval chain, holiday calendar, weekend- and holiday-aware',
            'covers'  => 'A6',
            'status'  => 'delivered',
        ],
        [
            'no'      => 4,
            'title'   => 'Shifts & schedule',
            'detail'  => 'Per-department shifts, per-employee overrides, night and rotating patterns, weekly roster with draft/publish, shift swaps',
            'covers'  => 'A5',
            'status'  => 'delivered',
        ],
        [
            'no'      => 5,
            'title'   => 'Mobile app',
            'detail'  => 'Flutter client for Android & iOS on the v1 API, with push notifications',
            'covers'  => 'B1–B6',
            'status'  => 'delivered',
        ],
        [
            'no'      => 6,
            'title'   => 'Attendance depth',
            'detail'  => 'Manual correction by HR with an audit reason, employee regularisation requests, overtime, break punches',
            'covers'  => 'A4.12–A4.15',
            'status'  => 'delivered',
        ],
        [
            'no'      => 7,
            'title'   => 'Reporting & payroll',
            'detail'  => 'Payroll-ready hours export, leave reports, scheduled email delivery, and a builder for the reports nobody anticipated',
            'covers'  => 'A7.10–A7.14',
            'status'  => 'delivered',
        ],
        [
            'no'      => 8,
            'title'   => 'Security & policy',
            'detail'  => 'Two-factor sign-in, a login audit trail, session timeout, the working-week editor and geofence enforcement',
            'covers'  => 'A1.7–A1.9, A2.3, A2.8, A4.16',
            'status'  => 'delivered',
        ],
        [
            'no'      => 9,
            'title'   => 'Employee records & leave depth',
            'detail'  => 'Photos, a document vault with expiry alerts, emergency contacts, the org chart, the roster export, leave accrual and carry-forward, the leave calendar, the live board and the late-arrival digest',
            'covers'  => 'A3.7–A3.11, A6.4/A6.7/A6.9, A4.19, A9.3',
            'status'  => 'delivered',
        ],
        [
            'no'      => 10,
            'title'   => 'Dashboards & checklists',
            'detail'  => 'Role-specific dashboards, week-on-week trends, weekly rollups, schedule-change alerts and on/offboarding checklists',
            'covers'  => 'A3.12, A4.11, A8.4–A8.6, A9.5',
            'status'  => 'active',
        ],
        [
            'no'      => 11,
            'title'   => 'Manager mode in the app',
            'detail'  => 'Team roster visibility inside the mobile app; approvals and team attendance already ship',
            'covers'  => 'B7',
            'status'  => 'planned',
        ],
        [
            'no'      => 12,
            'title'   => 'AI assistant',
            'detail'  => 'Natural-language questions over attendance and leave data — out of scope for the current phase, by decision',
            'covers'  => '—',
            'status'  => 'planned',
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
