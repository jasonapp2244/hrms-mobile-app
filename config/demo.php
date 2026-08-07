<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Quick sign-in panel
    |--------------------------------------------------------------------------
    |
    | Puts a one-click sign-in list on the login page so a client can walk
    | through every role without being handed a sheet of passwords.
    |
    | It prints working credentials — including an administrator's — in plain
    | text on a page that requires no account to reach. On a demo box that is
    | the whole point; on a live install it is a total compromise. So it is off
    | unless switched on, and the `&&` below forces it off in production no
    | matter what the environment file says. `hrms:preflight` fails a deploy
    | that somehow still has it on.
    |
    | Accounts are listed as `email:password`, comma separated. Nothing here is
    | trusted on its own: every entry is checked against the database before it
    | is shown, so the panel can never advertise a login that does not work.
    | Roles come from the database too, never from this file.
    |
    */

    'quick_login' => env('DEMO_QUICK_LOGIN', false) && env('APP_ENV') !== 'production',

    'quick_login_accounts' => env('DEMO_QUICK_LOGIN_ACCOUNTS', ''),

];
