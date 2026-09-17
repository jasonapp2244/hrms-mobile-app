<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * The company whose data this request may touch.
     *
     * **One definition, and it fails closed.** This used to be a method copied
     * into twenty-three controllers, each reading
     * `auth()->user()->company_id ?? Office::value('company_id')` — falling back
     * to whichever company happened to own the first office row when the signed
     * in user had none of their own.
     *
     * On a single-company install that fallback was a harmless convenience. It
     * is the wrong shape for A2.10: with a second company on the box it hands a
     * user with no company of their own a **different company's data**, silently
     * and with a 200. Twenty-three doors held shut only by the absence of a key.
     *
     * Nothing reached it — `users.company_id` is nullable but both paths that
     * create a user set it — which is exactly why it was worth closing while
     * that was still true rather than after somebody added a third path.
     *
     * A user with no company has nothing to be shown. Refusing says so;
     * guessing says something false in a way nobody can see.
     */
    protected function companyId(): int
    {
        $companyId = auth()->user()?->company_id;

        abort_if(
            $companyId === null,
            403,
            'This account is not linked to a company. Contact your administrator.',
        );

        return (int) $companyId;
    }
}
