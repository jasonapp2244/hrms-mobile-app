<?php

/*
|--------------------------------------------------------------------------
| Everything the server sends somebody (C1.18)
|--------------------------------------------------------------------------
|
| Push, the notification centre and the email behind them, all from one set of
| strings — the three have always said the same thing in slightly different
| lengths, and keeping one file makes that a rule rather than a habit.
|
| **These render in the recipient's language, not the sender's.** A notification
| is formatted when a worker picks it up, in a process with no request and no
| header, and it is usually triggered by somebody else: HR approving leave in
| English decides what an employee reads in Spanish. `User::preferredLocale()`
| is what the framework consults, and `users.locale` is what fills it in.
|
| A consequence worth knowing: the notification *history* stores the rendered
| words, so a row written before somebody switched language keeps the old one.
| Translating on read instead would mean storing keys and parameters, which is a
| schema change and would leave every existing row unreadable.
|
*/

return [

    // --- Leave asked for, for whoever it is waiting on --------------------
    'leave_submitted' => [
        'title' => ':name requested leave',
        // The push version: who and when, and stop. The reason, the clashes and
        // the buttons are one tap away.
        'push'    => ':type, :from to :to.',
        'body'    => ':days day(s) of :type, :from to :to.',
        'subject' => 'Leave request from :name',
        'line'    => ':name has requested :days day(s) of :type.',
        'dates'   => 'Dates: :from to :to.',
        'reason'  => 'Reason: :reason',
        'action'  => 'Review the request',
        'why'     => 'You are receiving this because the request is waiting on you.',
    ],

    // --- What happened to the leave you asked for ------------------------
    'leave_decided' => [
        'title' => [
            'approved'         => 'Your leave was approved',
            'rejected'         => 'Your leave request was declined',
            'cancelled'        => 'Your leave request was withdrawn',
            'manager_approved' => 'Your leave request has moved to HR',
            'default'          => 'Your leave request was updated',
        ],
        'body' => [
            'approved'         => 'Your :type for :dates has been approved.',
            'rejected'         => 'Your :type for :dates was not approved.',
            'cancelled'        => 'Your :type for :dates has been withdrawn.',
            'manager_approved' => 'Your manager approved :type for :dates. It is now with HR for the final decision.',
            'default'          => 'Your :type for :dates was updated.',
        ],
        'note'   => 'Note: :note',
        'action' => 'View your leave',
        'dates'  => 'Dates: :dates',
    ],

    // --- Still clocked in after the shift ended ---------------------------
    // --- Your shift is about to start (B5.1) -----------------------------
    // No mail leg: this one is useful for ten minutes. See the class.
    'shift_starting' => [
        'title' => 'Your shift starts soon',
        'push'  => 'You start at :time. Tap to clock in.',
        'body'  => 'Your shift starts at :time. Tap to clock in.',
    ],

    'missing_checkout' => [
        'title'   => 'You are still clocked in',
        'push'    => 'You clocked in at :time. Tap to check out.',
        'body'    => 'You clocked in at :time and no clock-out has been recorded. Tap to check out.',
        'line'    => 'You clocked in at :time on :date, and no clock-out has been recorded.',
        'finish'  => 'If you have finished for the day, please clock out.',
        // Said plainly, so nobody is surprised by the row appearing later.
        'auto'    => 'If nothing is recorded, the day will be closed automatically at your scheduled shift end.',
        'action'  => 'Clock out',
    ],

    // --- The published roster moved --------------------------------------
    'schedule_updated' => [
        'changed' => 'Your schedule has changed',
        'ready'   => 'Your schedule is ready',
        'summary' => ':days day(s) between :from and :to.',
        'action'  => 'View your schedule',
        'check'   => 'Check the times before your next shift — they may have moved.',
    ],

    // --- Addressed to HR, who work at a desk ------------------------------
    'document_expiring' => [
        'title_expired'    => 'Document expired',
        'title_expiring'   => 'Document expiring',
        'body'             => ':employee — :title (:type) :timing.',
        'subject_expired'  => 'Expired: :title — :employee',
        'subject_expiring' => 'Expiring soon: :title — :employee',
        'greeting_expired'  => 'A document has expired',
        'greeting_expiring' => 'A document is about to expire',
        'line'             => ':title (:type) for :employee :timing.',
        'action'           => 'Open their documents',
        'why'              => 'You are receiving this because you manage employee records.',
        'an_employee'      => 'An employee',
        'an_employee_lower' => 'an employee',
        'employee_word'    => 'employee',
        'timing' => [
            'none'          => 'has no expiry date',
            'expired_today' => 'expired today',
            'expired_days'  => 'expired :days day(s) ago',
            'expires_today' => 'expires today',
            'expires_days'  => 'expires in :days day(s)',
        ],
    ],

    'late_arrivals' => [
        'title'    => ':count late arrival(s)',
        'body_one' => ':name clocked in :minutes minute(s) late.',
        'body_many' => ':count people clocked in late on :date.',
        'subject'  => ':count late arrival(s) — :date',
        'greeting' => 'Late arrivals for :date',
        'intro'    => 'These clock-ins were past the shift start and outside the grace period.',
        'row'      => '· :name (:department) — in at :at, :minutes minute(s) late',
        'action'   => 'Open the late arrivals report',
        'why'      => 'You are receiving this because you hold the reporting permission.',
    ],

    // --- Password reset ----------------------------------------------------
    'password_reset' => [
        'subject'  => 'Reset your :app password',
        'greeting' => 'Hello :name,',
        'there'    => 'there',
        'line'     => 'Someone asked to reset the password for this account.',
        'action'   => 'Choose a new password',
        'expiry'   => 'This link stops working in :minutes minutes, and can only be used once.',
        'ignore'   => 'If this was not you, nothing has changed — you can ignore this email and your password stays as it is.',
    ],

    // Shared by every mail that opens with a name.
    'greeting' => 'Hello :name,',

];
