<?php

/*
|--------------------------------------------------------------------------
| What the API says back (C1.18)
|--------------------------------------------------------------------------
|
| Every sentence the mobile client can put in front of somebody. The `error`
| code beside each one never changes — a client branches on the code and shows
| the message — so these may be reworded and translated freely without anything
| on a handset needing to know.
|
| English is the source. A key added here needs the same key under lang/es, and
| `ApiLocaleTest` fails until it has one.
|
*/

return [

    // --- Refusals the whole API shares ------------------------------------
    'no_employee_record' => 'No employee record is linked to this account. Contact HR.',
    'validation_failed'  => 'The given data was invalid.',
    'unauthenticated'    => 'Authentication required.',
    'forbidden'          => 'You are not allowed to do that.',
    'not_found'          => 'Resource not found.',
    'request_failed'     => 'Request failed.',
    'too_many_requests'  => 'Too many requests. Please slow down.',
    'server_error'       => 'Something went wrong.',

    // --- Ranges, shared by history and the roster -------------------------
    'invalid_range'   => 'The start date must fall on or before the end date.',
    'range_too_large' => 'Ask for at most :days days at a time.',
    'future_day'      => 'That day has not happened yet.',

    // --- Signing in -------------------------------------------------------
    // Deliberately says nothing about which of the two was wrong.
    'invalid_credentials' => 'Those details do not match our records.',
    'account_disabled'    => 'This account has been disabled. Contact HR.',
    // Answered whether or not the address has an account, which is the reason
    // it is worded as a conditional.
    'reset_link_sent' => 'If that email address has an account, a reset link is on its way.',
    'signed_out'      => 'Signed out.',
    'signed_out_all'  => 'Signed out on all devices.',

    // --- Devices ----------------------------------------------------------
    'device_registered'   => 'Device registered for notifications.',
    // B1.6. Names no accusation: a shared password and a stolen one look
    // identical from the server, and only HR can tell them apart or fix either.
    'device_not_trusted'  => 'This account is signed in on a different phone. Ask HR to release it before signing in here.',
    'device_unregistered' => 'Device will no longer receive notifications.',

    // --- Profile ----------------------------------------------------------
    'profile_updated'  => 'Profile updated.',
    'password_changed' => 'Password changed.',
    'wrong_password'   => 'Your current password is incorrect.',

    // --- Documents --------------------------------------------------------
    'document_not_found' => 'No such document.',
    'document_missing'   => 'This document is no longer on file. Contact HR.',

    // --- Leave ------------------------------------------------------------
    'leave_not_yours'      => 'That leave request is not yours.',
    'leave_attachment_missing' => 'The file attached to this request is no longer on the server.',
    'leave_own_request'    => 'You cannot decide on your own leave request.',
    'leave_outside_team'   => 'That request belongs to someone outside your team.',
    'leave_auto_approved'  => 'Leave approved — this type does not need sign-off.',
    'leave_submitted'      => 'Leave request submitted. You will be notified once it is reviewed.',
    'leave_withdrawn'      => 'Leave request withdrawn.',
    'leave_passed_to_hr'   => ":name's request has been passed to HR for final approval.",
    'leave_rejected'       => 'Request rejected.',
    'leave_reason_needed'  => 'Please give a reason — the employee sees this.',

    // --- Corrections (A4.13) ----------------------------------------------
    'correction_not_yours' => 'That request is not yours.',
    'correction_submitted' => 'Request submitted. HR will review it.',
    'correction_withdrawn' => 'Request withdrawn.',

];
