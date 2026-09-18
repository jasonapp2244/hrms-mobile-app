<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a signed-in account has no employee record behind it.
 *
 * Its own class, and its own error code on the wire, because this is the one
 * refusal a client must be able to tell apart from every other refusal.
 *
 * It used to be a bare `abort(403)`, which the API renders as `forbidden` —
 * and so does an employee reaching for somebody else's leave request, and so
 * does one withdrawing somebody else's correction. Three unrelated conditions
 * arriving under one name is fine for a log and wrong for a client: the app
 * treats this one as permanent (an administrator belongs on the web dashboard
 * and no amount of retrying will change that) and the other two as ordinary
 * refusals. Sharing a code meant the app had to guess, and guessed the same
 * way every time.
 *
 * `HttpException` rather than `AccessDeniedHttpException` so the render arm in
 * bootstrap/app.php can match this before the generic status-to-code mapping
 * reaches it. The message is supplied there too, with every other translated
 * refusal, rather than at the raise site.
 */
class NoEmployeeRecord extends HttpException
{
    public function __construct()
    {
        parent::__construct(403);
    }
}
