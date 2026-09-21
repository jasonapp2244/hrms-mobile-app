# The web dashboard — what the client supplies, and how to use it

Two parts. **Part 1** is everything the client has to provide before the web
application can go live — web only, with nothing from the mobile app list.
**Part 2** is how to actually use it, in the order it has to be done.

The mobile app needs six further items; they are not repeated here. See
`Accounts-and-Services_Procurement-Guide.html` §3 when that phase is approved.

---

# PART 1 — What the client supplies for the web app

## 1.1 Accounts and services — nine items, none optional

| # | Item | Who | Cost | Lead time |
|---|---|---|---|---|
| 1 | **Domain** — `hrams.devonlinetestserver.com`, decided. The A record must point at the server **before** the certificate is requested | IT | Free as a subdomain of an existing domain, else $10–20/yr | Minutes to 24 h |
| 2 | **Server** — 2 vCPU / 4 GB Linux, Ubuntu 22.04 or 24.04, PHP 8.2+, MySQL. Comfortable for a few hundred employees | IT | $10–40/mo | Same day |
| 3 | **SSL certificate** — Let's Encrypt, free, auto-renewing. Confirm the host includes it before purchasing | Host | Free | Minutes |
| 4 | **SMTP provider** — Postmark, SES, Mailgun, Brevo, or existing Google Workspace / Microsoft 365 credentials | IT | Free–$25/mo | 1 h with existing credentials; 1–2 days for a new service |
| 5 | **Off-server backup storage** — object storage or another host | IT | Often included with hosting, else $1–5/mo | Same day |
| 6 | **Branding** — logo as SVG or transparent PNG, a square version, brand colour codes | Marketing | — | Internal |
| 7 | **Privacy policy / employee notice** | HR + Legal | — | **1–2 weeks** |
| 8 | **SmartHR template licence** — locate the receipt; confirm it is held by the company and is the correct tier | Whoever purchased it | $25–60 regular | Immediate |
| 9 | **SSH root access**, for the one-time setup | IT | — | — |

Two of these deserve emphasis.

**#4 is the fastest win on the whole project.** `MAIL_MAILER` is currently `log`,
which means password resets and leave notifications are written to a file and no
employee has ever received one. The features are built and tested; they are
inert behind one configuration line.

**#7 blocks rollout, not build.** Start it on day one — it runs in parallel with
everything else. The system records physical location at check-in and check-out,
which is sensitive personal data under GDPR and its equivalents, and employees
must be told before collection begins rather than afterwards. The single most
useful sentence in that notice is the one stating that location is recorded
**only at the moment of check-in and check-out, and never between** — that
resolves most objections before they are raised.

Register every account under a company address such as `it@company.com`, never
an individual's. An organisation that registers its domain and certificates
personally loses control of them the day that person leaves.

## 1.2 Information to hand over

| Item | Detail | Why it matters |
|---|---|---|
| Company details | Legal name, **timezone**, currency | Attendance is judged against shift times in the company timezone. A wrong value marks the entire workforce late every morning and produces plausible data rather than an error. |
| Offices / branches | Name, address, GPS coordinates, radius, work hours | Punches are attributed to an office; work hours drive the on-time / late decision. |
| Departments | Full list | **A department carries the shift.** Every employee must have one. |
| Designations | Job titles | Used on records and reports. |
| Shifts | Start, end, grace period, break | Defines late, early-leave, and paid hours. |
| Working week and holidays | Weekend days, public-holiday calendar | Weekends and holidays count as neither present nor absent, and are excluded from leave day counts. |
| Employee data | The 11-column import file | See §2.3. Template: `employee-import-template.csv`. |
| First administrator | Name, email, password | Created by `emp:install`. |
| Data retention period | How long records are kept | Goes into the privacy notice. |
| Support contact | An address staff can write to | Appears on the public pages. |

## 1.3 Five decisions

1. Should check-in be **blocked** outside the office, or only recorded? (Today
   coordinates are recorded and never used as a gate.)
2. Is leave management in scope for this phase?
3. Is the mobile app in scope?
4. Photo capture at check-in?
5. Working week and public holidays — confirmed by HR.

## 1.4 What needs nothing from the client

All 130 software libraries are free and open-source and require no account. The
deploy scripts, nginx configuration, systemd worker, cron schedule and the
`emp:preflight` gate are written and live in `deploy/`. See
`Deployment-Guide_Production.md` for the server-side procedure.

---

# PART 2 — How to use the web application

## 2.1 Who sees what

Four roles. The split is not cosmetic — an employee-role account cannot reach
the admin application at all, even for a permission it happens to share.

| Role | Sees | Cannot |
|---|---|---|
| **Admin** | Everything, including company profile, offices, roles and settings | — |
| **HR** | People operations: employees, attendance, leave, shifts, reports | Company profile, offices, roles, settings |
| **Employee** | The self-service portal only — clock in/out, own attendance, own leave, own swaps | Anything company-wide |
| **Manager** | The portal, **plus** an approvals inbox scoped to their own direct reports | Approve anyone who does not report to them |

Manager is held *in addition to* employee, never instead of it. Signing in sends
each person to their own home screen automatically — staff to `/dashboard`,
everyone else to `/employee/dashboard`.

Eighteen permissions sit under those roles and are editable at
**Administration → Roles** without touching code.

## 2.2 First-run setup — do it in this order

The order matters because each step depends on the one before it.

1. **Company** (`/company`) — name, logo, address, **timezone**, currency,
   weekend days. Set the timezone before anybody punches; changing it later does
   not re-judge attendance already recorded.
2. **Offices** (`/offices`) — one per site. Address, GPS coordinates, radius,
   and the work hours that decide on-time versus late.
3. **Departments** (`/departments`) — and assign each one a shift. This is the
   step people skip, and it is why employee import refuses a row without a
   department.
4. **Designations** (`/designations`) — job titles.
5. **Shifts** (`/shifts`) — start, end, grace period, break. Create these
   *before* departments if you prefer; they have to exist before a department
   can point at one.
6. **Employees** — individually, or in bulk (§2.3).
7. **Leave types** (`/leave-types`) — annual, sick, unpaid, casual, with their
   annual entitlements.
8. **Holidays** (`/holidays`) — the public-holiday calendar. This drives what a
   leave request costs and what counts as an absence.
9. **Leave balances** (`/leave-balances`) → **Generate** — creates the year's
   balances for everyone from the leave types. Without this, people have
   entitlements on paper and nothing to spend.
10. **Roster** (`/shifts/roster`) → **Publish** — staff see published weeks only.

Only after step 10 does the system have everything it needs to judge a punch
correctly.

## 2.3 Bringing employees in

**Employees → Import** (`/employees/import`). Download the template first — the
button is on that screen, and the file is also at the repository root as
`employee-import-template.csv`.

Eleven columns, including office, department, designation and reporting manager,
all matched **by name** rather than by id. Two behaviours worth knowing:

- **The whole file is validated before anything is written.** A file with
  problems in rows 4, 40 and 300 reports all three at once and imports nothing,
  rather than importing 39 rows and stopping. Fix and re-upload.
- **Department is required**, because it carries the shift. An employee with no
  department has no shift, and an employee with no shift cannot be judged late.

Reporting lines are cycle-safe — the import refuses an arrangement where two
people end up managing each other.

Creating an employee also creates their login. Passwords can be reset from the
employee record by HR, or by the employee themselves through **Forgot password**
on the login screen.

## 2.4 The daily screens

### Dashboard (`/dashboard`)
Live tiles — present, late, absent, headcount — a seven-day trend chart, and a
recent-activity feed. This is the screen to leave open.

### Attendance → Overview (`/attendance`)
Today in one view: the daily summary, recent punches, and who is on approved
leave. Leave is reported as leave, not as absence.

### Attendance → Logs (`/attendance/logs`)
The full punch table — employee, office, type, time, status — filterable by
office and date and paginated. Each punch carries the coordinates and IP address
it arrived with.

**Punches cannot be edited or deleted.** The log is append-only by design: it is
the evidence base for payroll and for any employment dispute, and every write
records the actor, the source, the IP and a full snapshot. A correction is made
by adding a record, never by rewriting one.

### Attendance → Report (`/attendance/report`)
Date range, office and department filters, with **PDF** and **Excel** export.
Location and IP appear as columns in both.

### HR Reports (`/reports/…`)
Three analytical views: **Late** arrivals, **Outliers**, and **Department**
comparison. All three respect the same filters and the same leave-aware logic —
an approved leave day is never counted as an absence.

## 2.5 Leave

Leave has two halves and they live in different places.

**The employee's half** is in the portal (`/employee/leave`): balances, apply,
track, withdraw. Days are counted around weekends and holidays automatically.

**The company's half** is at **Leave** (`/leave`) — the register of every
request, with the final approval step.

The chain is two steps: **line manager, then HR.** An employee with no manager
set goes straight to HR. Days are deducted only on final approval, so a request
sitting with a manager has not yet cost anyone anything. Conflicts — two people
from the same team away on the same day — are flagged to the manager at the
point of decision.

**Leave balances** (`/leave-balances`) is where HR adjusts an individual
entitlement, regenerates the year, or recalculates one balance that has drifted.
There is no automatic accrual engine; balances are provisioned annually and
adjusted by hand.

## 2.6 Shifts and the roster

- **Shifts** (`/shifts`) — the definitions. Start, end, grace period, break.
  Night and rotating patterns are supported.
- **Roster** (`/shifts/roster`) — a weekly grid planner, aware of leave,
  holidays and the company weekend. Build it as a draft, then **Publish**.
  Employees see published weeks only, so an unpublished plan can be reworked
  freely.
- **Rotation** generates a repeating pattern rather than requiring each week to
  be filled by hand.
- **Shift swaps** (`/shift-swaps`) — employees request swaps between themselves
  in the portal; a manager approves. HR uses this company-wide register for the
  case a single manager cannot handle: a swap between two people who report to
  different managers.

A per-employee override beats the department's shift, for the person who works
different hours from the rest of their team.

## 2.7 The employee portal

What staff actually use, at `/employee/dashboard`:

- **Clock in / clock out** — one button. It works from a phone browser or a PC,
  from any location. The server decides in or out from the last punch, so there
  is nothing to get wrong, and a double-tap inside the cooldown reads as success
  rather than as an error.
- **Own attendance** — history and totals.
- **Leave** — balances, apply, withdraw.
- **Swaps** — request, accept, decline.
- **Approvals** — managers only, scoped to their own reports.

Timestamps come from the server, never from the device clock.

## 2.8 Notifications

The bell (`/notifications`) is one inbox shared by the dashboard and the portal,
so a manager reads the same message whichever side they are signed in on. Leave
request, approval and rejection alerts are routed automatically, and a
missing-checkout reminder goes out a configurable grace period after a shift
ends.

Email versions are queued and rendered — but they only leave the server once
`MAIL_MAILER` is set to something other than `log` **and** the queue worker is
running. The in-app bell writes directly and keeps working regardless, which is
exactly what makes the gap easy to miss: the dashboard looks correct while no
employee is being told anything.

## 2.9 Administration

- **Roles** (`/roles`) — tick and untick the 18 permissions per role.
- **Settings** (`/settings`) — general application settings.
- **Company** (`/company`) — profile, logo, timezone, weekend configuration.
- **Profile** (`/profile`) — any signed-in staff user's own details and password.

## 2.10 Public pages

`/privacy` and `/account-deletion` are reachable **without signing in**, and
deliberately so — a person who has left the company still needs a route to
request removal, and both app stores require a privacy policy a reviewer can
open with no account. They render the company's own name and HR address, so they
read as the employer's pages rather than the software's.

---

## Things that will bite, in order of likelihood

1. **Wrong company timezone.** Everyone is late, every day, and nothing errors.
2. **No queue worker.** Every email and push is queued and silently undelivered
   while the notification bell keeps working perfectly.
3. **`MAIL_MAILER` left as `log`.** Same symptom, different cause.
4. **`TRUSTED_PROXIES` unset.** Every punch records nginx's address, so the IP
   column becomes 200 identical rows of `127.0.0.1`.
5. **Employees imported without departments.** No department means no shift,
   which means no late/on-time judgement.
6. **Leave balances never generated.** Staff have entitlements and cannot spend
   them.
7. **Roster left unpublished.** Staff see nothing and assume the feature is
   broken.

`php artisan emp:preflight` catches 1 through 4, and is the gate `deploy.sh`
runs last.

---

*Written 2026-08-06 against the live codebase — routes, controllers and the
seeded permission set read directly rather than from an earlier edition of any
document.*
