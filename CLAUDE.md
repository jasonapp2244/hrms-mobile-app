# KEMP — Klutch Employment Management Program (backend)

*Known internally as the Employment Management Portal (EMP) until the rebrand,
which is why `emp` is still the database name, the `emp:` artisan prefix and the
deployment subdomain. Those are identifiers, not the product name, and renaming
them would buy nothing and break every runbook.*

Working notes for the **Laravel 12 + MySQL API and web dashboard**. The Flutter
client lives in its own repository, `hr-mobile`, and talks to this one over
`/api/v1`. See **The boundary between this repo and the other one**, below,
before changing anything the app can see.

This file is for whoever picks the project up next. It records the things that
are **not** obvious from reading the code, and the traps that have already cost
time.

**The trap numbers below are not contiguous, and that is deliberate.** They were
numbered while both halves lived in one repository; the Flutter ones went to
`hr-mobile` keeping their numbers, because about ten traps refer to each other
by number in prose and renumbering would have broken those references silently.
A gap means a trap that belongs to the app.

## Running it

MySQL must be started **from the XAMPP Control Panel** — launching `mysqld.exe`
as a background task does not persist, it exits.

```bash
php artisan serve            # http://127.0.0.1:8000
php artisan test             # 1561 tests, ~300s, SQLite in memory
```

The Laravel application is the **root of this repository**. It used to sit in an
`hrms/` subdirectory when the app lived alongside it; `deploy/deploy.sh` already
handled all three layouts (`./`, `./hrms`, `./emp`) by detection rather than
configuration, so the split needed no change to it.

`config('app.timezone')` is **deliberately UTC** and must stay that way. Per-company
time comes from `Company::tz()`. "Fixing" it to a local zone would shift what
"today" means for every other company.

**A handset cannot reach `127.0.0.1` on this machine.** A Flutter developer
pointing `hr-mobile` at a local server needs the LAN address, or `10.0.2.2` on
the Android emulator.

### Signing in locally

**A plain `php artisan db:seed` creates no users at all** — `DatabaseSeeder`
calls `RolePermissionSeeder` and nothing else, deliberately, so that seeding a
real installation cannot conjure an account with a known password. Accounts come
from one of two places instead.

**`php artisan emp:install`** — one administrator, on an email and password you
are prompted for. No employee record, which is intended: see below.

**`php artisan db:seed --class='Database\Seeders\DemoDataSeeder'`** — the demo
company, and seven accounts, **all on the password `password`**:

| Email | Roles | Employee | On the phone |
|---|---|---|---|
| `james.smith@acme.test` | employee + manager | EMP-0001 | Everything, **including the Team tab** |
| `emily.johnson@acme.test` | employee | EMP-0002 | The five employee tabs |
| `michael.brown@acme.test` | employee | EMP-0003 | The five employee tabs |
| `jessica.davis@acme.test` | employee | EMP-0004 | The five employee tabs |
| `david.wilson@acme.test` | employee | EMP-0005 | The five employee tabs |
| `hr@emp.test` | hr | EMP-0006 | The five employee tabs, **no Team tab** |
| `admin@emp.test` | admin | *none* | Signs in, then the no-employee-record state |

Four reporting lines run to EMP-0001, which is what makes `is_manager` true for
that account and nobody else — the app needs the `approve-leave` permission
**and** a direct report before it draws the Team tab, and these accounts are the
pair that tells those two apart. **HR holds the permission and leads nobody**,
on purpose.

**HR got its employee record on 2026-09-16, and had none before that.** The user
and the role had always been there, so nothing failed — HR simply landed on the
admin empty state on all four employee screens, and two of the four roles could
not be shown on a handset at all. `tests/Feature/Api/DemoRoleAccessTest` now
pins what each role gets, because the only previous way to find this out was to
sign in and look.

**Admin deliberately has no employee record and must not be given one.** An
administrator operates the system rather than working for the company. The
refusal it produces is a designed, tested screen with no retry button on it, and
a seeder that "fixed" this would hide the one state four screens are built
around.

The demo panel puts one-click sign-in buttons on the login page:

```
DEMO_QUICK_LOGIN=true
DEMO_QUICK_LOGIN_ACCOUNTS="admin@emp.test:password,hr@emp.test:password,james.smith@acme.test:password,emily.johnson@acme.test:password,michael.brown@acme.test:password,jessica.davis@acme.test:password,david.wilson@acme.test:password"
```

**Every account named gets a button**, sorted by role — admin, HR, manager, then
the employees by name. It showed one per role until 2026-09-17, four at most,
which was solving a real problem the wrong way round: the env file on the live
box named two employees and no manager, so the area a client most wants to see
was unreachable and the one they had already seen appeared twice. Collapsing to
one per role hid that; the cause was the env file, and the panel could not fix
it by showing less. The five demo employees differ in what they have *done* —
leave taken, punches made — not only in the role they hold, so which one you
land on is the tester's choice, not the panel's. The button leads with the
person's name for that reason; "Employee" five times over names nobody.

**The panel does not create anybody** — it is a list of credentials to fill the
form with, so every address in it has to exist already, and every one is checked
against the stored hash before it is drawn. A row that would not sign in is
never rendered, which means **a missing button is the panel telling you
something**: wrong password, deactivated account, or no account at all. The
local `.env` used to name `test.admin@local.test` and two siblings that no
seeder in this repository creates; they were made by hand, so on a fresh
database those buttons silently failed to appear. It now names the seven
`DemoDataSeeder` accounts, which `db:seed --class='Database\Seeders\DemoDataSeeder'`
will always create. **Quote that class name** — unquoted, bash eats the
backslashes and Laravel reports `Class "DatabaseSeedersDemoDataSeeder" does not
exist`.

It is forced off when `APP_ENV=production`, and `emp:preflight` fails a deploy
that still has it on.

### Browser testing

**The Claude-in-Chrome extension cannot reach `127.0.0.1:8000`** — it shows an
error page regardless of the URL, and it is a site permission only the user can
grant. Use the **chrome-devtools MCP** instead (`new_page`), which works against
localhost first time.

**MySQL does not have to be running to drive the real UI.** Laravel's Dotenv is
immutable, so a real environment variable beats the `.env` line, and the whole
app will run against a throwaway SQLite file without editing anything:

```bash
export DB_CONNECTION=sqlite
export DB_DATABASE='C:\path\to\a\scratch\smoke.sqlite'   # must already exist
php artisan migrate --force
php artisan db:seed --force --class='Database\Seeders\RolePermissionSeeder'
php artisan db:seed --force --class='Database\Seeders\DemoDataSeeder'
php artisan serve --port=8123
```

**Seed the roles first.** `DemoDataSeeder` assigns roles it does not create, so
on its own it dies on `RoleDoesNotExist: admin`. And **the demo company is
`America/New_York`**, which is the first thing to check when a punch you expect
to be late comes back `ontime`: `record()` works in `Company::tz()`, so 09:50
UTC is 05:50 to the rule that judges it. The five demo employees are not all on
the nine o'clock shift either — EMP-0003 starts at 13:00 — so read
`shiftOn($today)` before choosing a time rather than assuming one.

This is worth doing for anything whose behaviour lives in the browser. The rule
builder is drawn entirely in JavaScript from the vocabulary the controller hands
it, so PHPUnit can prove the page returns 200 and the row stores correctly and
still tell you nothing about whether the form works.

---

## Traps that have already bitten

These are not hypothetical. Each one shipped, and each was invisible until
something specific broke.

### 1. Date-cast columns and range queries

`work_date` and `shift_assignments.date` are `date` casts. MySQL holds a real
DATE; **every other engine stores `"2026-08-04 00:00:00"`**, and
`"2026-08-04 00:00:00" <= "2026-08-04"` is false as a string.

So `whereBetween` and `whereIn` on those columns **silently drop the last day of
every range**, and a single-day range returns nothing at all. Invisible in
production, and equally invisible in the tests, which run on SQLite.

**Always use the model scopes:**

```php
AttendanceLog::forDates($from, $to)      // never whereBetween('work_date', …)
ShiftAssignment::between($from, $to)     // never whereBetween('date', …)
LeaveRequest::overlapping($from, $to)
```

Four separate bugs came from this. Assume a fifth is waiting.

### 2. `validate()` drops absent nullable keys

`$request->validate(['x' => 'nullable|date'])` returns an array **without `x`**
when the caller never sent it. Reading `$data['x']` is then an undefined-index
500, not the fallback you intended. Bit twice (`employee_code`, `anchor_date`).

```php
$value = ($data['x'] ?? null) ?: $fallback;   // always
```

### 3. Blade directives glued to the preceding word

`clock@if($x)` is **not** a directive — Blade leaves it literal but still
compiles the `@endif`, so the view fails to parse at all. Took the live board
down entirely. Always leave a space before `@if`.

### 4. Undeclared `company_id` columns

MySQL carries them, migrations do not declare them, SQLite tests stay green
because the column does not exist there. Bit on `attendance_logs` and
`leave_balances`. All nine company-scoped tables were audited and are consistent;
if you add a tenth, declare it in a guarded `hasColumn` migration **and** fill it
in the model's `booted()`, not at call sites.

### 5. `actingAs` persists for the whole test

A "signed out" assertion after an `actingAs` call is still signed in and asserts
nothing. Log out explicitly, or build the fixture without authenticating.

### 6. "Last punch" is not "clocked in"

There are **four** punch types, not two. `break_start` and `break_end` are
neither `in` nor `out`, so anything that reads the day's last row to decide
whether somebody is on the clock treats a returning employee as one who went
home — and the next press opens a second attendance stretch, losing the
morning's pairing.

`AttendanceService::record` gets this right by filtering to `whereIn('type',
['in','out'])`. The API's `/attendance/today` did not: it shipped reading
`$logs->last()`, so an employee who took a break on the web portal and then
opened the app was offered "Check In" while still on the clock. Nothing failed;
the screen was just wrong.

**`breakState()` is the one definition** — it is what the live board uses, and
what `today` uses now. Never re-derive this from a punch list.

### 7. The punch cooldown measures `created_at`, not `scanned_at`

`recentlyScanned()` compares `created_at` against `now()`. In a test that means
**`travelTo` before writing the fixture punch, not after**: a row written at the
real clock and then compared against a travelled `now()` is a negative diff,
which reads as "within the cooldown", and every POST in the test returns 429
`duplicate_scan`. Seven tests failed this way at once and the message points at
the endpoint rather than the fixture.

```php
$this->travelTo(Carbon::parse('2026-08-03 09:00:00'));   // first
$this->punch('in', '2026-08-03 09:00:00');
$this->travelTo(Carbon::parse('2026-08-03 13:00:00'));   // then move on
```

---

### 16. One list per side for the notification route

`route` decides which tab a notification opens, and it used to be written out
by hand in every `toPush()` on the server and listed again in `PushRoute` on
the app. The two drifted: `schedule` was sent for months to a build whose enum
had never heard of it, and because an unknown route opens the app normally
rather than crashing, nothing ever said so.

There is now one list on each side. On the server, **`App\Support\AppRoute`**
maps a notification's `type` to its route, and both the push payload and the
notification history (B5.6) read it. In the app, `PushRoute.parse` handles
both a pushed route and a listed one, so `AppNotification` cannot invent a
second answer.

It is keyed on `type` rather than on anything only a push carries, because
`toDatabase()` has never recorded a route — so every row already in the
`notifications` table has to get its answer from the type alone.

**Null is an ordinary answer.** `document_expiring` and `late_arrivals` are
addressed to HR, who work at a desk; the app has no screen for either, and a
notification with nowhere to go simply offers no button.

### 21. A notification is not written in the language of the request that caused it

HR approves leave in English; the employee reads Spanish. The message is
rendered by a **worker**, in a process with no request and no `Accept-Language`
header at all — so the language cannot come from the request, and
`app()->getLocale()` at send time is whoever pressed the button (C1.18).

The framework already has the answer, and it is the only one that covers push,
the notification centre and the email in one go: `User` implements
`HasLocalePreference`, and `Illuminate\Notifications\NotificationSender` wraps
every send in `withLocale($notifiable->preferredLocale())`. Nothing in a
notification class knows about locales; they just call `__()`.

`users.locale` is what fills it in, and **nobody types it**. `SetApiLocale`
writes the header there in `terminate()`, so the column is a record of what
somebody is actually being shown rather than a second preference to maintain. A
null — every account that has only ever used the web dashboard — resolves to the
default.

**The notification *history* keeps the words it was written with.** A row
written before somebody switched language stays in the old one. Translating on
read instead would mean storing keys and parameters in `notifications.data`, a
schema change that would also leave every existing row unreadable, for a payoff
nobody has asked for.

### 22. `*/` inside a docblock ends the docblock

`` `lang/*/leave.php` `` in a comment closes the block four words early and the
file stops parsing, with the error pointing at whatever line follows. Obvious in
hindsight, ten minutes in practice. Write it as "the `leave.status`
translations", or any other way that does not contain the sequence.

### 23. The exception's own message outranked the translation

`bootstrap/app.php` built the error payload as
`$e->getMessage() ?: $message`, so that an `abort(403, 'That leave request is
not yours.')` reached the client with its own wording rather than a generic
"forbidden". Reasonable, and it quietly undid half of C1.18: the exceptions the
framework raises **carry an English message of their own**.
`AuthenticationException` is "Unauthenticated.", `AuthorizationException` is
"This action is unauthorized.", `ThrottleRequestsException` is "Too Many
Attempts." — so the two refusals a handset meets most often came back in English
while everything around them was Spanish.

Each arm of the `match` already decides what to say, including the one that
prefers an abort's own message, so the payload takes `$message` and nothing
else.

**Nothing in the suite noticed**, and nothing was going to: 1154 tests and not
one of them read the `message` on a refusal — they assert the status and the
`error` code, which is exactly what the client is supposed to branch on. It took
a `curl` against a running server. Two tests cover it now, and the general
lesson is worth more than either: **a test suite that only asserts the fields a
client acts on cannot see anything about the fields a person reads.**

### 24. A roster time is a wall clock, and it was being read as UTC

A shift stores `end_time = '17:00:00'` and means five o'clock **where the
company is**. `shiftEndFor()` did `Carbon::parse($workDate.' '.$shift->end_time)`
with no zone, which is five o'clock UTC — and both its callers compare the
result against `now($company->tz())`, which is an **instant**, not a wall clock.
Carbon compares instants, so the two silently disagreed by the company's offset.

For a company four hours behind, "has the shift ended?" answered yes four hours
early: `attendance:remind-checkout` nudged people at lunchtime, and
`attendance:close-day` wrote an automatic clock-out — with the *scheduled*
hours — while they were still working. Every test passes because every test
company is on `UTC`, where the bug does not exist. The seeded install is UTC
too, so it would have surfaced on the first non-UTC client and nowhere before.

Both helpers now parse in `tzFor($employee)`. Note the one that must **not**:
`scheduledMinutesFor()` measures a duration, and a zone applied to one end and
not the other turns an eight-hour shift into a four-hour one — both sides there
are parsed the same way, deliberately.

`config('app.timezone')` being UTC is correct and must stay that way (see
"Running it"). That is exactly why anything holding a *company's* wall clock has
to say so at the point it is parsed.

### 25. A scheduled window can fall between two runs

B5.1's clock-in reminder fires inside `[start − lead, start)` — a window that
closes, unlike every other job here, which fires once a moment has passed and
can afford to be late. With the quarter-hourly cadence the other attendance jobs
use and the default ten-minute lead, an 09:00 shift's window is 08:50–09:00: the
08:45 run is too early and the 09:00 run is too late. **Nobody is ever
reminded** — no error, no log, no failing test, for every employee, forever.

`attendance:remind-checkin` is scheduled `everyFiveMinutes()` and
`PolicyController` refuses a lead between 1 and 4 minutes so the two ends cannot
drift apart. If the cadence is ever slowed, the validation has to move with it.
The general shape: **a job whose window closes needs an interval shorter than
the shortest window it can be asked for**, and the coupling has to be written
down at both ends because nothing enforces it at runtime.
### 26. A new permission cannot arrive by re-running the seeder

`RolePermissionSeeder` ends in `syncPermissions()`, which is right for a fresh
database and wrong for a live one: it does not add, it **replaces**. A client who
had taken `export-reports` away from HR through the Roles & Permissions editor
(A1.4) would find it handed back on the next deploy.

Which is why `deploy/deploy.sh` runs `migrate --force` and no seeder at all — and
why a permission added to the seeder alone reaches a fresh install and every
test, and **never reaches a running server**. The menu simply would not appear,
with nothing in any log to say why.

So a new permission is declared in two places, on purpose: the seeder for a new
database, and a data migration using `givePermissionTo` — additive, idempotent,
leaving every other grant as the administrator left it — for the ones already
out there. See `2026_09_11_000002_add_manage_announcements_permission.php`.

The path that matters in production is also the one `RefreshDatabase` cannot
reach, since migrations run before the seeder and the roles do not exist yet.
`AnnouncementTest` calls the migration's `up()` by hand for that reason.

### 27. A broadcast has no undo, so the model has to say so

Publishing an announcement (B5.5) writes a row into every recipient's
`notifications` table and pushes to every registered handset. Neither can be
recalled, so the register's own copy is refused an edit **on the model**, not
just in the controller — `booted()` throws on `updating` and `deleting` once
`published_at` is set, the same rule the attendance and activity trails keep.
The controller catches it and turns the exception into the sentence explaining
why; a console command or a future endpoint gets the exception.

`publish()` is the one caller allowed past the guard, and it goes through
`forceFill(...)->saveQuietly()`.
### 28. A score of zero and no score at all are different answers

B3.5's attendance score is `ontime / obliged`, and `obliged` is legitimately
zero — a window of weekends, a fortnight of booked leave, somebody's first week
before they started. Returning 0 for that is arithmetically defensible and
completely wrong in front of a person: it reads as a failure, and the person
most likely to see it is somebody just back from leave.

`scorePercent()` returns **null**, the API sends `null`, and the card draws
"No score yet" with a line saying why. The same rule applies to anything else
here that divides by a count of days.

The other half is the streak, and the trap there is the opposite: an unfinished
day is not an absence. Counting today against somebody before the day is over
would show every employee in the company a zero every morning — the feature
working perfectly and being useless. `onTimeStreak()` skips today when there is
no punch yet, and counts it the moment there is one.

### 30. The end of a window is only today when nobody asked for less

The app never names a date the server has not named first: it asks for the
default window, reads `to` out of the reply, and counts from that. Everything
dated on the History and Roster screens is built that way, because the phone is
wherever its owner is and attendance is judged in the company's zone.

`to` is the window's end. It is today **only because nothing earlier was
asked for**. The month grid (B3.4) is the first caller to send both ends, and
paging back to March gets `to: 2025-03-31` — a true statement about that
window, and not a statement about today. Anchoring on it moved the app's idea of
today to the end of whichever month was being read: the forward arrow went dead
one month back, and switching to the list then asked for thirty days ending
three weeks before today. Both screens looked internally consistent. Nothing
errored.

**So the anchor is taken only from a reply to a request that named no `to`.**
Widening the rule — "read the echo, it comes from the server" — is what broke
it; the echo is only today's date when the request left `to` alone. A reply to a
bounded window can be drawn, but it cannot be used to tell the time.

The same applies to `from`: it may never be later than the anchor, because a
window starting after today comes back empty and reads as a month nobody
attended. `test/history_calendar_test.dart` runs its mock server in **April
2025**, nowhere near the machine, so a date built from the handset fails on the
first expectation instead of passing for eleven months of the year.

**A screen that *offers* a date is covered by this rule too, and two were not.**
`showDateRangePicker` and `showDatePicker` take `currentDate` — the day they
draw a ring around — and Material defaults it to `DateTime.now()`. Caught on the
handset: the phone was on 15 September, the company (America/New_York) on the
14th, and the Clock, History and Schedule tabs all said the 14th while the leave
picker ringed the 15th. Somebody booking "from today" books the wrong day, and
the app visibly disagrees with itself.

On the corrections form it was worse than cosmetic: `lastDate` is a **rule** —
the server refuses a correction to a time that has not happened — so the picker
offered a date the app itself would then be refused for. `/leave/balances` and
`/attendance/regularisations` now carry `today` in the company's zone, and both
pickers take `currentDate`, `firstDate` and `lastDate` from it. `date('Y')` went
with them: PHP's `date()` reads the machine clock and ignores
`Carbon::setTestNow` entirely, so that line could not be made to fail in a test
no matter what timezone the company was in — an unfreezable clock is its own
reason not to ask one the time.

### 36. `env()` in `bootstrap/app.php` is read before .env exists

`TRUSTED_PROXIES` was configured in the `withMiddleware` closure:

```php
if ($proxies = env('TRUSTED_PROXIES')) { $middleware->trustProxies(at: …); }
```

**That guard was never once true**, on any box, in any environment, for the
whole life of the file. `withMiddleware` registers its callback on
`afterResolving(HttpKernel::class)`, and `Application::handleRequest` resolves
the kernel on the line *before* it calls `$kernel->handle()` — and `handle()` is
what runs `LoadEnvironmentVariables`. So the closure runs before .env has been
read and `env()` answers null. Verified rather than reasoned about: an
`afterResolving` hook registered alongside it reports `env('DB_DATABASE')` as
null too, on a build with no cached config at all.

This is **not** the familiar "don't call `env()` outside config files once you
run `config:cache`" rule. That one at least works until the first cache. This
fails always, and silently, because the value is optional by construction: a
null proxy list is indistinguishable from "no proxy in front of this box".

The consequences were all quiet. Behind Varnish, `$request->ip()` is the
proxy — so every punch filed the proxy's address (`Api\AttendanceController`),
the IP column in exports was a column of one repeated value, and the **login
rate limiter keyed on that address**, which turns A1.10's "one person's mistakes
cannot lock out a colleague" into its exact opposite: five wrong passwords
anywhere in the company, and the whole company is locked out.

It now lives in `config/trustedproxy.php`, read by the framework's own
`TrustProxies` at request time through its documented
`config('trustedproxy.proxies')` fallback — which means config is loaded by the
time it is read, and a config file is the only place `env()` survives
`config:cache`. `emp:preflight` reads the same config key rather than `env()`,
for the same reason: after a deploy has cached the config, an `env()` check
would report "unset" on the box that had just set it correctly.

`tests/Feature/TrustedProxyTest` drives the real punch endpoint through the real
global middleware stack. **Four of its five tests would have passed against the
broken build** — they set the config key directly, and the framework's fallback
has always worked. Only `test_the_env_variable_reaches_the_key_the_framework_reads`
covers the actual fix, which is why it exists and why removing
`config/trustedproxy.php` fails that one and nothing else.

**The lesson worth carrying:** a setting whose absence is legal cannot be
verified by reading the code that consumes it. Somebody has to set it and watch
the effect. Nobody had.

### 37. A check that cannot tell a healthy box from a broken one is noise

Trap 36 ends on "a setting whose absence is legal cannot be verified by reading
the code that consumes it. Somebody has to set it and watch the effect." The
setting moved to `config/trustedproxy.php` and `emp:preflight` grew a line for
it — and that line could still only ever report what configuration said:

```php
$proxies ? self::PASS : self::WARN
```

**Unset is the correct answer on a single-server install**, which is most of
them, so the check was yellow on the boxes that were fine. A warning that fires
where nothing is wrong is a warning people learn to scroll past, and it was the
only thing standing between a proxied box and an `attendance_logs.ip_address`
column that agrees with itself on every row. It warned loudest exactly where it
mattered least.

Configuration could never settle this, because the missing fact is not in the
configuration: it is whether a proxy is actually in front. **Traffic knows.**
`DetectUntrustedProxy` watches for a forwarding header — `X-Forwarded-For`,
`Forwarded`, `X-Forwarded-Proto` or a bare `Via`, since a cache may add only the
last — arriving while nothing is trusted, and leaves one marker. Preflight reads
it and fails, quoting the header, the address the proxy claimed and the address
actually stored, so the line argues its own case instead of asking to be
believed.

Three details that are load-bearing rather than tidy:

- **The configured path returns before touching the request.** This is global
  middleware; on a correct box it must cost one cached-config array lookup and
  nothing else.
- **`Cache::add`, not `Cache::put`.** One write per TTL rather than one per
  request, so a busy misconfigured box is not billed for its own diagnosis.
- **A sighting is ignored the moment proxies are configured.** The marker lives
  a week; fixing the setting must clear the failure by itself, or the next
  person is debugging a cache key instead of a deploy.

**The general shape:** when a check has to guess, give it evidence instead of a
louder default. `WARN` is what a check says when it does not know — and the cure
for not knowing is to go and find out, not to warn at everybody and hope the
right person reads it.

### 38. An escape hatch covers everything it was not meant to cover

`deploy.sh` ran preflight like this on a staging box:

```sh
$PHP artisan emp:preflight || echo "    (advisory only on a non-production install)"
```

The reasoning in the comment above it was sound: `MAIL_MAILER=log` and the
quick-login panel are *why a demo box exists*, and a script that fails on them
teaches people to stop running the script. What the line actually did was
downgrade **every check in the command**. An empty `APP_KEY`, an administrator
still on the seeded password `password` on a public URL, an unreachable
database, a critical CVE from `composer audit` — all printed red, and the deploy
carried on and said "Done".

It also silently defeated the check added one commit earlier: trap 37's whole
point was that a corrupted audit trail should *refuse* a deploy rather than
warn, and this flag turned it straight back into a warning.

The fix is not to remove the hatch, which was answering a real problem. It is to
make it selective, and the test for membership is: **could this state be
somebody's deliberate choice?** Mail to the log is a choice. A demo panel is a
choice. Nobody chooses an unencrypted session store, a published admin password,
a database nothing can reach, a punch filed against the proxy's address, or a
package with a known hole. Those five are `Preflight::ALWAYS_FATAL` and
`--non-production` does not touch them; everything else downgrades to a warning
that still prints, annotated so a reader knows why it is yellow.

**Two things this cost that are worth remembering.**

The command reads the sighting from the cache, the default cache store is the
database, and the proxy check runs *before* `checkDatabase()`. So on a box with
MySQL down, preflight died on an uncaught `QueryException` and reported nothing
at all — on precisely the misconfigured box it exists to diagnose. A preflight
that can throw is a preflight that checks nothing. The read is wrapped, and
falls back to the warning rather than to a pass: not being able to look is not
the same as having looked and found nothing.

And that was found by **running the command**, not by the tests, which were
green throughout. The same lesson as trap 36 — "somebody has to set it and watch
the effect" — arriving from the other direction: a check whose own failure mode
is invisible to the suite that covers it.
---
---

## The boundary between this repo and the other one

**This section is the one thing deliberately written in both repositories.**
Everything else was split; these four rules describe the seam itself, and a rule
about a seam that lives on only one side of it is a rule the other side will
break. **Change it in both, in the same week, or it becomes the drift it exists
to prevent.**

The two halves:

| Repo | Holds | Deploys to |
|---|---|---|
| `hr-backend` | Laravel 12 API + web dashboard, `deploy/`, the API reference | `hrams.devonlinetestserver.com` |
| `hr-mobile` | The Flutter app | Play Store / App Store |

### 1. `API-Reference_v1.md` is authoritative in `hr-backend`

`tests/Feature/Api/ApiDocsTest` walks the real route table and **fails the build**
on an endpoint or an error code that is not in that file. So the backend copy
cannot drift from the API; it is checked on every run.

`hr-mobile` carries a **copy**, and the copy names the backend commit it was
taken from in its first line. Nothing enforces that stamp — it is the one place
in this arrangement where staleness is possible. When the API changes, the
backend PR updates the reference and the app PR re-copies it, quoting the new
commit. If the stamp looks old, trust the backend.

### 2. The app never names a date the server has not named first

Trap 30 in both files, and it is the mistake this codebase has made four times.
The phone is wherever its owner is; attendance is judged in the company's
timezone. The app asks for a default window, reads `from`/`to` out of the reply,
and counts from those. **A screen that builds a date from `DateTime.now()` is a
bug even when it looks right on the machine it was written on.**

### 3. The notification route lists must agree

Trap 16 in both files. `App\Support\AppRoute` on the server decides where a
notification points; `PushRoute` in the app decides what it can open. A value
the app has never heard of opens the app normally and does nothing, which is
safe and completely silent — `schedule` was being sent for months before the app
knew it. **Adding a route means a change in both repos**, and the server one
lands first because the app ignores what it cannot parse.

### 4. Capabilities are decided by the server, never derived by the app

`/auth/me` returns a `can` block — `lead_team`, `decide_leave`,
`view_employees`. The app reads the conclusion. It used to work `leadsATeam` out
from the permission list while the route table enforced something subtly
different, which is how every HR user came to have a permanently empty Team tab.
**A new area in the app is a new key in that block, not a new rule in Dart.**

## Conventions

- **A list's page size comes from `$this->perPage()`, never a literal.** There
  were twenty-four of those literals across twenty-one controllers — twenty-three
  `paginate(n)` calls plus one `const PER_PAGE` that a grep for a digit did not
  find — spread across five values with no rule anybody could state for which
  list got which. It was drift: each list was written on a different day and
  picked a number that looked right on that screen. `config/pagination.php` now
  holds them, with the reason beside each, and **the existing numbers were kept
  rather than flattened to one** — a dense audit table and an employee's own
  leave list genuinely do not want the same count, and collapsing them would
  have been a visual change made under cover of a refactor. An unknown list key
  falls back to the default rather than throwing, so a typo costs a slightly
  wrong page length instead of a 500 on a screen that was working.
  **On the API the same call also reads `per_page` off the request**, clamped to
  `pagination.api.max` — see `ApiController::perPage()`. Clamped rather than
  validated, on purpose: a list that 422s because a client asked for one row too
  many fails a person reading their own leave to protect a server that could
  have answered, and `meta.per_page` reports what was actually used. Each
  endpoint's default is the size it served *before* the parameter existed, so an
  app that sends nothing still gets what it always got.
- **A new message the API can return goes into `lang/en/` *and* `lang/es/`**
  (C1.18). A missing key does not fail — it falls back to English and ships as
  an English sentence inside a Spanish screen — so `ApiLocaleTest` compares the
  two key sets, checks Laravel's own `validation.php` against the framework's,
  and flags a Spanish row left as the English text pasted across. The `error`
  code beside a message is **not** a translation: it is the contract, the client
  branches on it, and it never changes. Reach for `Clock::time()` rather than
  `format('h:i A')`: the meridiem is a translated string now.
- **A new string goes into `mobile/lib/l10n/app_en.arb` *and* `app_es.arb`**
  (B6.2). English is the template, so a key missing from the Spanish file falls
  back to the English text and nothing fails — which is exactly why
  `test/locale_test.dart` reads gen_l10n's own `untranslated.json` and fails
  when it is not empty, and separately catches a row left as the English
  sentence pasted across. Reach the strings with `context.t` (trap 19 says where
  not to). `lib/l10n/generated/` is build output and git-ignored: `flutter pub
  get` and every build regenerate it, so a fresh clone needs no extra step.
- **Editing Blade files: use a `php <<'PHPEOF'` heredoc**, not the Edit tool and
  not inline `php -r`. The templates are tab-indented and the strings do not
  round-trip; nested quotes break in Git Bash.
- **Attendance is append-only.** Edit and delete throw; punches are voided
  instead, and every write records actor, source, IP and a full snapshot.
  `ActivityLog` and `AttendanceAuditEvent` refuse updates and deletes too.
  **Deleting an *employee* was the way around this**: `attendance_logs.employee_id`
  is `ON DELETE CASCADE`, so removing somebody took every punch they ever made,
  including the ones a finished payroll run was calculated from. Deletion now
  refuses anyone with history and points at `status = terminated` instead.
  Employees still have no soft delete, so the refusal is the only thing standing
  between a mis-click and a hole in the audit trail — leave it in place.
- **The login throttle fires `Lockout`, not a 429.** `LoginController` counts
  attempts itself rather than wearing `throttle` middleware, because
  `AppServiceProvider` already listens for Laravel's `Lockout` event and writes
  the audit row that the Security panel's "Lockouts (24h)" tile counts. Route
  middleware would block the requests and leave that tile reading zero straight
  through an attack. The counter is keyed on **email *and* IP**: on email alone
  one person's fat fingers would lock out a colleague behind the same office NAT
  address; on IP alone the whole office shares one budget.
- **The offline cache only ever answers for a request that did not arrive.**
  `OfflineCache.fetch` falls back to the saved copy on
  `ApiException.isNetworkFailure` and rethrows everything else, because a
  refusal *is* an answer: serving yesterday's roster over today's 403 hides an
  account that has just lost its employee record. Two more rules go with it.
  **Nothing that takes a decision is cached** — a leave balance from disk talks
  somebody into booking days they no longer have, and an approvals inbox offers
  a manager a request that was settled an hour ago. And **every saved copy is
  labelled on screen** with when it was taken (`OfflineBanner`); a roster that
  is quietly three days old is worse than no roster, because nobody is given a
  reason to doubt it. Today's clock screen has a third rule of its own — it is
  refused unless its `date` is still today, or it would greet somebody with
  "clocked in since 09:00" from yesterday evening.
- **The cache holds PII and is a plain file, so it is cleared with the token.**
  `Session._clearToken` clears it alongside the punch queue, and `restore()`
  clears it when there is no token at all — which is the only sweep that
  catches a session ended by `logout-all` on another device. It never holds the
  bearer token itself: `_cacheProfile` writes the `user` object only, and the
  login response it comes from carries a token beside it. There is a test that
  greps the file for one.
- **Every new policy defaults to off.** `session_idle_timeout_minutes` (0),
  `enforce_geofence` (false), `require_two_factor_for_staff` (false). Each would
  otherwise change behaviour for a working installation on upgrade. They live in
  `Company::POLICY_DEFAULTS` and are edited at `/settings/policies`.
- **Reports return a uniform shape** — `title, subtitle, tiles, headings, rows` —
  so the screen, the PDF and the Excel export are all generic. Row keys must
  match `headings` exactly or the exports throw.
- **The API-docs test walks the route table.** A new endpoint fails the suite
  until it is written up in `API-Reference_v1.md`. That is deliberate.
- **`APP_NAME` is `KEMP`**, and it lives in `.env`, which is gitignored. Only
  `hrms/.env.production.example` carries it in the repository, so an install
  that skips it reads "Laravel" everywhere. It is also the TOTP issuer:
  changing it relabels **new** 2FA enrolments only — existing ones keep the old
  label and keep working, because the shared secret is untouched. It was
  `Klutch Cleaning - Employment Management Portal (EMP)` until the KEMP
  rebrand, so anybody already enrolled still sees that in their authenticator.
- **`MAIL_FROM_NAME` must not inherit `${APP_NAME}`**, even now that the name is
  short enough that it could. The From column should say who is writing, and to
  somebody opening a leave decision on their phone that is their employer, not
  the software. Set it by hand to `Klutch Cleaning`.
- **`App\Support\SqlDumper` must stay free of the container.** `SqlDumperTest`
  is a plain `PHPUnit\TestCase` with no application booted, so a `config()` call
  anywhere in that class dies with *Target class [config] does not exist* and
  takes three backup tests with it. The product name in the dump header is a
  literal for exactly that reason, and a backup must not need a booted framework.
- **The logo `<img>` tags carry explicit `width` and `height`.** The assets are
  PNGs, and above 992px there is **no CSS width for `.logo img` at all** — the
  old SVGs sized themselves through their intrinsic `width="250"`. Swap in an
  asset without those attributes and it renders at natural size and blows the
  sidebar open. `logo-small.png` is a black badge with the mark knocked out
  white because it is the one element shown in *both* light and dark
  mini-sidebar; the template has no dark variant for it.

---

## The four roles

Admin, HR, manager, employee — 18 permissions, all seeded by
`RolePermissionSeeder`.

| | Admin | HR | Manager | Employee |
|---|---|---|---|---|
| Lands on | `/dashboard` | `/dashboard` | `/manager/dashboard` | `/employee/dashboard` |
| Roles, policies, activity log, settings | ✅ | ❌ | ❌ | ❌ |
| Employees, reports, leave register | ✅ | ✅ | ❌ | ❌ |
| Own team: dashboard, attendance, roster, reports | — | — | ✅ | ❌ |
| Team approvals | — | — | ✅ | ❌ |
| Clocks in through the portal | ❌ | ❌ | ✅ | ✅ |

**`manager` is a role *and* a relationship, and both must line up.** The role
grants the gate; `employees.manager_id` decides the scope. Role but no reports →
empty team, not an error. Reports but no role → 403.

The admin app is wrapped in `role:admin|hr`, which runs **before** any
`permission:` middleware on the route inside it. Adding `|approve-leave` to a
route in that group advertises manager access that can never be reached — the
manager holds the permission but not the role.

### The manager area (`/manager/*`)

A parallel route group, **not** part of the admin app, for exactly the reason
above: `role:admin|hr` refuses a manager at the door whatever permission they
hold, so the only way the role can be reached is a group of its own. Gated
`role:manager` **and** `permission:view-team` — the role decides who is in, the
permission decides whether the area exists at all, so the roles editor can
withdraw it without anybody editing the route table. (`view-team` was seeded
from the start and wired to nothing until this area existed.)

Neither gate knows *whose* team. That is **`App\Services\ManagerScope`**, and
every manager query goes through it:

```php
$this->scope->team($manager)            // active direct reports, ordered
$this->scope->teamIds($manager)         // ids, for the whereIn
$this->scope->assertManages($m, $emp)   // 403 unless they report to $m
```

**The scope is direct reports only, and does not recurse.** Leave approval is a
single hop — manager, then HR — which is what `LeaveService::managerApprove`
models, so a manager two levels up is not in the chain and showing them those
records would hand them data they can never act on. Subtree visibility is a
different feature and belongs to whoever also changes the approval chain.

No new tables were added: `employees.manager_id` already carries the reporting
line. A manager↔office or manager↔department pivot would be a second claim on
the same fact, and the two would eventually disagree about who owns whom.

Managers are **read-only** outside approvals — deliberately, not by omission:

- Correcting a punch is `manage-attendance`; attendance is append-only and every
  write records an actor. The routes back are the employee's regularisation
  request (A4.13) or HR's correction screen (A4.12), both of which leave a trail.
- Planning the roster is `manage-shifts`. One planner, one publish step.
- Exporting is `export-reports`. The manager reports print instead.
- The HR-grade PII on an employee record — national ID, home address, date of
  birth, emergency contact, the document vault — is behind `manage-employees`
  and never rendered in `/manager`. A supervisor needs to know who is on shift.

**Two shells, one approvals inbox.** `LeaveApprovalController` serves both
`employee.approvals.index` and `manager.approvals.index`; the view picks its
layout from a `$layout` variable. The approve/reject **writes stay on the portal
routes only** — one write path, already scoped, rather than two places for that
check to be forgotten.

**`homeRoute()` now has three answers, and `landing()` must not assume two.**
It used to compare against `'employee.dashboard'` and send everything else to
`route('dashboard')`, which sent managers somewhere `role:admin|hr` refuses — a
sign-in ending in a 403. `landing()` tests the roles directly now. An admin who
also manages a team lands on `/dashboard`: the bigger screen wins.

**`shiftOn()` is roster-aware but *not* publish-aware.** It reads
`shift_assignments` directly, so it will happily return an unpublished draft's
shift. Anything whose contract is "published only" — the team roster, the app's
`/team/roster` — must fall back to `$employee->shift` (the standing shift) when
there is no *published* assignment, never to `shiftOn()`. That leaked a draft
shift's name onto the roster before it was caught.

**Every mobile API call needs an employee record.** `ApiController::employee()`
aborts 403 "No employee record is linked to this account". A hand-created admin
has no employee row, so it signs in and then 403s on nearly everything.

**An employee record and a login are separate rows, deliberately** — plenty of
staff are on the payroll and never touch the system. The link is
`employees.user_id`, and it is made on the employee's own page under **Sign-in
Account** (`EmployeeAccountController`). Which roles that screen offers depends
on the viewer: `manage-employees` grants `employee` and `manager`, and the
elevated `hr` and `admin` need `manage-roles`. Without that split HR — who hold
`manage-employees`, because onboarding is their job — could mint an account,
make it an admin and sign in as one. The same rule runs in reverse, so HR cannot
demote an existing administrator either.

---

### The HR area in the app (`/api/v1/hr/*`) — added 2026-09-22

At the client's request. Before this, HR used the app as an ordinary member of
staff — five tabs, no team — and did HR work at a desk. **Most of that is still
true**: only leave decisions and the employee register moved, and the rest of
the dashboard is deliberately still web-only.

**The gate is `manage-leave` and `manage-employees`, and neither is a new
rule.** They are what `routes/web.php` already puts on the company-wide leave
register and the employee screens. A line manager holds `approve-leave` and
*neither of these*, so the permission that keeps them out of the register on the
web keeps them out of the API — rather than a fresh "approve-leave and not a
manager" condition that would have been a second definition free to drift.

**`Api\LeaveApprovalController` and `Api\HrLeaveController` are two different
steps and must stay that way.** The first is the manager's: scoped to their own
reports, and its approve button calls `managerApprove()`, which passes the
request up and **spends nothing**. The second calls `approve()`, which commits
the days. If a manager could reach the second they would be granting company
leave without anybody having decided they may — `HrLeaveDeskTest` asserts both
sides of that.

**`/auth/me` now carries a `can` block**, and the app reads it instead of
deriving anything:

```json
"can": { "lead_team": …, "decide_leave": …, "view_employees": … }
```

This exists because `leadsATeam` was worked out in Dart *and* enforced in the
route table, and the two drifted — which is how every HR user came to have a
permanently empty Team tab. A third copy for HR would have repeated it on a
screen that spends leave balance. `lead_team` keeps its local fallback so an old
app against a new server loses nothing; **`decide_leave` has none on purpose** —
an app that cannot ask whether it may spend balance must not decide that it may.

**Decisions are never queued offline.** A punch taken with no signal is held and
synced, because the punch already happened and the clock is the record; a
decision has not happened until the server says so, and one replayed from a
queue would spend the balance twice. This is the one place the app deliberately
does not behave like the clock.

**`approve()` speaks in `ValidationException`** because the web posts forms at
it. `HrLeaveController` catches that and returns `leave_decision_refused` with
the message, so the app can show words instead of a 500 — and the queue sends
`balance.would_exceed` up front, which is the same comparison made *before* the
tap rather than after.

**The register is not the directory.** `/directory` (B3.8) answers "who else
works here" for everybody and withholds date of birth, address, national id, the
emergency contact and the reporting line; `/hr/employees` returns them.
`HrEmployeeRegisterTest` pins both halves — that HR sees the record, and that an
ordinary employee reading the directory still does not. Add to it before
widening either.

**Three traps this feature walked into, all already documented above:**

- **Trap 1 again, the fifth time.** `whereBetween('work_date', …)` in the
  attendance summary returned nothing on SQLite, because a `date` cast is stored
  as a midnight timestamp and the string compare drops the last day. Use
  `AttendanceLog::forDates()`. The note said to assume a fifth was waiting.
- **A filled button inside a `Row` throws.** The app theme sets
  `minimumSize: Size.fromHeight(50)` on `FilledButton` — a width of *infinity*,
  which is what makes them full-width in a column. A Row's main axis is
  unbounded, so that minimum becomes a **tight infinite width** and layout
  asserts rather than overflowing. Override `minimumSize` on any filled button
  in a row; the manager's approval card already did, which is why it never
  showed up before.
- **`models.dart` exports a `Directory`** (the company one), which shadows
  `dart:io`'s in any test that imports both. Import it with `show`.

---

## Where things stand

The web dashboard is **complete except for two deliberate omissions** — the AI
assistant and multi-company tenancy, both below. It said *four* until
2026-09-21, and listed three, which is what a count maintained by hand does: the
conditional rule builder was one of them and has since been built, and the
fourth had already gone without the number following it. **The web is now
complete but for the two.** The mobile app and the API are done — B2.8, the
launcher quick action, was the last buildable row on either half, and since it
landed **every remaining ⬜ on the board is parked by decision rather than
outstanding**. `Feature-List_Web-and-App.md` is the live status
board — read it first — and `hrms/config/roadmap.php` drives the phase panel on
the Settings screen.

**Not built, by decision:**

- **AI assistant** (Part D) — out of scope, parked.
- **Multi-company tenancy** (A2.10) — the schema is company-scoped throughout, so
  this is a routing and onboarding job rather than a data-model one.

  **That sentence is now tested rather than asserted.** It had been the premise
  the whole feature rests on and had never been checked.
  `tests/Feature/CrossCompanyIsolationTest` stands up two companies — each with
  an administrator, a manager and a direct report — and attempts **36 real
  crossings**: as an administrator (reads, edits, deletes), as a manager (team
  screens and decisions), as an employee (the self-service withdrawals, where
  the guard is ownership rather than company), over the API, and as a user with
  no company at all. Plus listings, which must not merely refuse but must not
  *contain* the other company's rows — and the `team/*` endpoints, which take no
  id, so what is tested there is an absence rather than a refusal. All hold.
  Mutation-checked each time it grew: pulling a guard out fails exactly the
  tests that should. **Add to it when you add a route that takes a bound model**
  — three different guard idioms are in use (`authorizeCompany`,
  `authoriseCompany`, a bare `abort_unless`), plus ownership and team checks, so
  reading the neighbouring controller is not a substitute for a test.

  **What is genuinely left is smaller than the ⬜ suggests** — the full working
  was in `Multi-Company_Tenancy-Assessment.md`, removed along with the other
  client documents; recover it with
  `git log --diff-filter=D --oneline -- Multi-Company_Tenancy-Assessment.md`
  and `git show <commit>^:Multi-Company_Tenancy-Assessment.md`. Two companies can already be
  created (`emp:install --force`, or `--company-id=N` to attach an admin to an
  existing one) and administered separately. What remains:

  1. ~~Close the `?? Office::value('company_id')` fallback.~~ **Done
     2026-09-15.** `companyId()` now lives once, on the base `Controller`, and
     **fails closed**: a user with no company gets a 403 saying so, not
     whichever company owns the first office row. The 19 identical copies of the
     method are gone. **Call `$this->companyId()` — never re-derive it**; the
     old line answered `200` with another company's dashboard, which is why it
     was worth closing while `users.company_id` still happened to be set
     everywhere.
  2. **A product decision, not an engineering task**: creating a company is
     CLI-only and there is deliberately no sign-up route — a public "create your
     company" form on the client's own server would let anyone create tenants on
     it. CLI-only, invite-only or open sign-up is the client's call, and
     building the wrong one is worse than building none.
  3. Spatie's `roles`/`permissions` carry no `company_id`, so all companies share
     one set. Defensible — the four roles and 19 permissions mean the same thing
     everywhere — but record it as a decision rather than leaving it a discovery.

### The conditional rule builder (A2.9, A6.6) — built 2026-09-21

**This was the last row on the board that was neither built nor parked by
decision**, and it used to be a bullet in the list above. The two halves are
worth telling apart: the company *policies* — the working week, the reminder
windows, the geofence, the default day — are single values that apply to
everybody, and the *rule builder* is the screen for what a single value cannot
say. "When anyone in the Croydon depot clocks in more than twenty minutes late,
tell their manager" is not a setting; it is a row.

`settings/rules`, behind `manage-settings` beside the policies, **with no
permission of its own** — a rule decides who the system speaks to about
everybody's attendance, which is the decision that screen already makes, and a
`manage-rules` would have put a second name on the same authority.

**The vocabulary lives in `PolicyRule` and nowhere else.** `FIELDS`, `OPERATORS`
and `ACTIONS` are read by the form that offers a field, by
`PolicyRuleController` which validates what comes back, and by `RuleEngine`
which evaluates it. Three things have to agree and only one copy can be right;
adding a field to that constant adds it to all three. Anything outside it is
**refused on save and skipped on read** — the second half is the one that
matters, because a rule naming a leave type deleted last month must not throw
inside somebody's leave request.

**The engine runs inside the path that records a punch**, so every layer fails
soft and each rule runs in its own `try`: a bad row, a missing field, an action
naming nobody, a mail server that is down — the punch is written regardless, and
one malformed rule does not silence the three good ones after it. The same
holds on the leave side. `RuleEngineTest` pins both directions.

**A rule notifies and records. It never writes to attendance or leave**, and
that is a design decision rather than an unfinished one: a rule that could
change a punch's status or decide a request would put a second, invisible author
on rows payroll and a tribunal both read, and the person reading the row would
have no way to tell which of the two wrote it.

**Conditions are ANDed and there is no OR.** An OR needs grouping, grouping
needs parentheses, and parentheses need a builder nobody can use without
training. Two rules say the same thing, each legible on its own line.

**The notification carries no link, deliberately.** One rule can address HR, an
administrator, the line manager and the employee at once; a notification carries
one destination for all of them, and the screens worth pointing at are
permission-gated, so most of that audience would tap through to a refusal.
Without a url `NotificationController::open` lands on the notification list,
which is the honest answer.

**Two traps for whoever extends it:**

- **The call and the method behind it shipped apart once.** `LeaveService::submit()`
  called `runLeaveRules()` before that method existed, and every leave request
  raised on that build died on `Call to undefined method`. No punch test could
  have caught it, because the punch half was complete. `RuleEngineTest` now
  opens its leave section with a test that raises a request and asserts nothing
  about rules at all — the floor, rather than the feature.
- **`notice_days` is signed.** `diffInDays()` without its third argument is an
  absolute value, and a rule written to catch leave booked *after* it started
  would then match a fortnight's notice just as well. It is measured in the
  company's timezone, because "today" is the client's rather than the server's.

The screen is covered by `PolicyRuleScreenTest`, which is deliberately the
larger of the two files: what may *become* a rule decides whether the engine is
ever handed something it cannot read, and every one of those failures is silent
— a rule that stores fine and never fires looks on the list exactly like a rule
waiting for somebody to be late.


### The default day (A2.9) — built the same day, and a smaller thing

**The policies really are all configurable as of 2026-09-21, which the board
had been claiming for a while and was not quite true.** `determineStatus()`
fell back to a literal `09:00:00`–`17:00:00` with 15 minutes' grace on any
day no shift was rostered for — an unplanned day, or a rostered day off
somebody worked anyway. It was the last business rule in the codebase that
no client could move, and it is wrong for any company that does not keep
office hours: an early shift judged against nine o'clock can never be
recorded as late at all, and nothing on any screen would have said why.

It is three company settings now — `default_day_start`, `default_day_end`,
`default_day_grace_minutes` — living in `Company::POLICY_DEFAULTS` beside the
eight that were already there, editable on the Policies screen, and read
through `AttendanceService::dayPolicy()`. **Company settings rather than a
config key**, deliberately: on a multi-company box one client's ordinary
morning is another's overtime, and `config/attendance.php` cannot say that.
The defaults are the literals they replaced, so no existing row is restated.

**A default day may not run overnight**, and the form refuses one with a
reason. A rostered night shift can, because its roster row carries the date
its hours belong to; an unrostered day has no such row, so an evening
arrival would be measured against tomorrow morning and every night worker
marked early. Stored and quietly wrong is the worse of the two outcomes.

Ten tests on the behaviour and four on the form, mutation-checked: ignoring
the company setting fails exactly the four that set one, and letting the
default outrank a rostered shift fails exactly the one that forbids it.
**Adding a required field to `PolicyController` breaks every fixture that
posts that form** — `SecurityPolicyTest` has seven, and the field list in
`test_the_policy_form_renders_with_every_field_on_it` is what stops a field
being required by the controller and missing from the blade, which locks the
whole page. Extend both when you add the next one.

**Built since, and this list used to say otherwise:** the **2FA QR image** was
recorded here as blocked, on the grounds that composer could not resolve a new
dependency because of a `league/commonmark` advisory. On 2026-09-14 composer
resolved `bacon/bacon-qr-code` on the first attempt, and upgraded
`league/commonmark` 2.8.3 → 2.10.1 and `maatwebsite/excel` 3.1.69 → 3.1.70 in
the same pass; `composer audit` is clean. The setup screen now draws the
`otpauth://` URI as inline SVG beside the typed key — see A1.7 in
`Feature-List_Web-and-App.md`. `App\Support\Totp` is still hand-rolled and still
verified against the RFC 6238 vectors, and does not reference the QR package.

A blocker nobody re-tested outlived the thing blocking it. Re-read any note of
that shape against the tool before planning around it.

**The API answers in the caller's language** (C1.18). `SetApiLocale` reads
`Accept-Language` on the API group only, so a web request is untouched. Three
things stay in the language they were typed in, because they are data rather
than vocabulary: leave types, office and department names, and the maintenance
message on `GET /app/status`. `/privacy` and `/account-deletion` are web pages
and are English too.

**Inert until configured — neither is a code change:**

- `MAIL_MAILER` is still `log`. Password resets, leave decisions, scheduled
  reports and document-expiry warnings are all built and tested, and all go
  nowhere until real SMTP is set.
- Push is silent until a Firebase project exists. See `Push-Notifications_Setup.md`.

---

## Deploying

**The app is live at `https://hrams.devonlinetestserver.com`** — permanent, not a
staging step on the way to somewhere else. It is managed webspace (CloudPanel,
Varnish in front, no systemd), so the queue and the scheduler are cron-driven
and `HOSTING_MODE=managed` is what keeps `emp:preflight` from failing a healthy
cron queue on a daemon's five-minute tolerance.

Ship a revision with:

```bash
cd /home/devonlinetestserver-hrams/htdocs/hrams.devonlinetestserver.com
ALLOW_NON_PRODUCTION=1 bash deploy/deploy.sh
```

**The flag is required and is not a workaround.** That box's `.env` says
`APP_ENV=staging`, and `deploy.sh` refuses to run rather than guess which
database to migrate. Leave it saying staging: setting it to `production` would
also switch preflight from advisory to blocking, and it would then fail the
deploy on `MAIL_MAILER=log`.

- `Deployment-Guide_Production.md` — the runbook.
- `deploy/` — nginx config, the systemd worker unit, the cron line, `deploy.sh`.
- `hrms/.env.production.example` — the env template.
- `php artisan emp:preflight` — gates a deploy. Fails on debug-on,
  `MAIL_MAILER=log`, a localhost or http `APP_URL`, the sync queue, no recent
  backup, a bad company timezone, the demo panel left on, seeded passwords, and
  **a critical or high dependency advisory** (`composer audit`, run inside the
  command). Medium and low advisories warn instead — blocking an urgent fix on a
  low-severity advisory in a dev-only tool is how a check gets ignored. It never
  fails because it *could not* look: composer missing, no network or a timeout
  all warn and say which, since the advisory database is fetched over the wire
  and plenty of boxes have no outbound access.

**Preflight on that box now reports 24 passed, 3 warnings, 1 failure**, and the
one failure is not a code change. As of 2026-09-17:

- **`MAIL_MAILER` is `log`** — the only failure left, and blocked on SMTP
  credentials rather than on anything in this repository. `MAIL_FROM_ADDRESS` is
  still `hello@example.com` and warns alongside it. Password resets, leave
  decisions, scheduled reports and document-expiry warnings are all built and
  tested, and all go nowhere until this is set.
- Warnings: `APP_ENV=staging` (deliberate — setting it to `production` would
  switch preflight from advisory to blocking and then fail the deploy on
  `MAIL_MAILER`), the mail from address, and push disabled until a Firebase
  project exists.

**All three of the original failures are closed**, and two of them closed
without anybody doing it in a deploy — the note here had simply outlived them:

- `Demo quick-login` was turned off on 2026-09-17. `Demo credentials` had
  already started passing on its own: no account carries the seeded password
  any more, so by the time the panel came down it was publishing addresses that
  did not work. Both were true before anyone re-read this paragraph.
- **`TRUSTED_PROXIES` reads `127.0.0.1`, and the effect is proven rather than
  assumed.** It had been recorded here as unset; it is set, and after trap 36 it
  is also actually *read*. Varnish is on the loopback, so `127.0.0.1` is right
  rather than a placeholder. **Checked on 2026-09-17 by reading
  `attendance_logs.ip_address` on the live box: it holds a routable client
  address, not `127.0.0.1`.** That is the whole test — before the config file
  shipped, a punch behind Varnish could record nothing *but* the loopback, so a
  public address in that column can only come from `X-Forwarded-For` being read.
  Passing preflight would not have shown this: preflight only proves the key
  holds a value.

Re-read a status paragraph against the box before planning around it. This one
was wrong in two particulars out of three, in the direction of pessimism.

**`TRUSTED_PROXIES` is only an `.env` line as of 2026-09-15** — before that it
was an `.env` line plus a bug, and setting it would have changed nothing. See
trap 36: the value was read in `bootstrap/app.php`, which runs before .env is
loaded, so it had never once been true. It now lives in
`config/trustedproxy.php`, which is where the framework's own middleware looks
and the only place `env()` survives `config:cache`. Whoever sets it on that box
should confirm the effect rather than assume it: make a punch and read
`attendance_logs.ip_address`.

**`db:backup --verify` cannot verify on this host.** The panel's database user
cannot `CREATE DATABASE`, so the scratch restore is skipped with a warning and
the dump is written but unproven. That is handled — the command warns rather
than failing the deploy — but it means the nightly dumps are not actually known
to restore. Prove one by hand occasionally on a machine that can.

**The database dump is not the whole backup.** Contracts, ID scans and employee
photos live on disk (`storage/app/employee-documents/`, `storage/app/public/avatars/`).
A restored database with no files behind it is a list of documents that all 404.

**`public/storage` must exist.** Without the symlink every employee photo 404s
with nothing in the log and no error on screen. `deploy.sh` runs `storage:link`
and preflight checks for it.

**There is no asset build step, deliberately.** The UI is the SmartHR Bootstrap 5
template served straight out of `public/assets`; no view uses `@vite`. Laravel's
default `package.json`, `vite.config.js`, `resources/js` and `resources/css` were
scaffolding nothing imported, and were deleted so nobody deploys expecting an
`npm ci && npm run build` that would produce nothing. Deployment is PHP only.

---

---

## Git

`git push` can hang on a hidden credential-manager dialog —
**`GIT_TERMINAL_PROMPT=0 git push`** completes instantly. `gh` is not installed,
so PRs must be opened through a browser link.

**History before the split is intact.** This repository was carved out of the
combined one with `git subtree split --prefix=hrms`, so every commit that ever
touched the backend is here, with its original message and author. `deploy/` was
brought in the same way. What is *not* here is the Flutter side of any commit
that touched both — `git log` for those shows only the server half, which is the
point.

The combined repository still exists and is the place to look if you ever need
to see a change across both halves as it was originally made.
