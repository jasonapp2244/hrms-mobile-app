# KEMP — Feature List (Web Dashboard, API, AI)

**Legend:** ✅ Built · 🟡 Partial · ⬜ Planned

The server half of the board — Part A (web dashboard), Part C (shared backend
and API) and Part D (the AI assistant, out of scope). The Flutter app is tracked
in `hr-mobile/Feature-List_App.md`, which is where Part B lives.

**Counts for this half:**

| Area | Built | Partial | Planned | Total |
|---|---|---|---|---|
| Web Dashboard (A) | 102 | 2 | 2 | 106 |
| Backend / API (C) | 18 | 0 | 0 | 18 |
| AI Assistant (D) | 0 | 0 | 7 | 7 |
| **Total** | **120** | **2** | **9** | **131** |

The app adds 57 built rows on its own board, for **177 built of 188** across
both repositories.

**The web dashboard is complete, AI excluded.** Two planned rows and two partial
ones remain across Part A, and none of them blocks a production deployment.

**The two partial rows are both waiting on something other than code:** A9.2,
which is email and waits on SMTP credentials, and A4.17, where absence stays
derived rather than written — a decision rather than a gap.

**Still open, and worth being explicit about:** multi-company tenancy (A2.10),
and roster editing and attendance correction by managers (A10.11 — held with
`manage-shifts` and `manage-attendance` on purpose).

---

# PART A — WEB DASHBOARD (Admin / HR)

## A1. Authentication & Access Control
| # | Feature | Status |
|---|---|---|
| A1.1 | Secure login / logout (Laravel session) | ✅ |
| A1.2 | Role-based access — Admin, HR, Manager, Employee (Spatie RBAC) | ✅ four roles, each landing on the only area it can reach. Manager is a first-class role with its own dashboard (A10), not a permission bolted onto the employee portal |
| A1.3 | Granular permissions per role (18 seeded) | ✅ |
| A1.4 | Roles & permissions editor UI | ✅ |
| A1.5 | Profile page + change password | ✅ |
| A1.6 | Password reset via email ("forgot password") | ✅ request → emailed link → new password; revokes app tokens and push. Needs a real `MAIL_MAILER` to leave the box |
| A1.7 | Two-factor authentication (2FA) for Admin/HR | ✅ TOTP, any authenticator app; secret encrypted at rest, 8 single-use recovery codes, optional company-wide requirement on Admin/HR. **Setup is by scan or by typed key** — the `otpauth://` URI is drawn as a QR beside the key, never instead of it: a camera that will not focus, a desktop authenticator and a password manager on the same machine all need the key, so replacing one with the other would have been a regression dressed as an improvement. The code is **inline SVG**, not an `<img>` pointing at a route — it encodes the TOTP secret, and a second request for it would put that secret in the web server's log and possibly in a proxy cache; inline, it inherits the protection the page already has. SVG rather than PNG so nothing depends on `ext-gd` being compiled into the host's PHP. The encoder is `bacon/bacon-qr-code` and lives in `App\Support\QrCode`, which `Totp` does not reference: the algorithm stays free of any vendor, and Reed-Solomon over a Galois field is not the forty lines of obvious arithmetic that HMAC is — hand-rolling it would have bought a class of bug that shows up as "my phone will not scan this" rather than as a failing test. A code that cannot be encoded returns null and the page draws the key alone rather than falling over |
| A1.8 | Login activity & audit trail (who did what, when) | ✅ immutable log of sign-ins, failed attempts, lockouts, timeouts, password and settings changes; filterable by event, person, date and IP. Admin-only. **The trail was silent about the whole mobile app until 2026-09-15, and silent about self-service password changes on both halves.** The design was right and the wiring was not: `AppServiceProvider::recordAuthenticationEvents` hangs the trail off the framework's auth events precisely so one listener covers every door, and its own comment named "the mobile API's token endpoint" as one of them — but token login checks the hash itself and never calls `Auth::attempt`, so `Login` and `Failed` were never dispatched and **not one sign-in from a handset had ever been recorded**. Separately `PASSWORD_CHANGED` had a label and a badge colour on the screen and was written by nothing at all, so a password changed by whoever currently holds an account left no trace — the one move an attacker makes on every account they take; only an HR-initiated reset was logged. Both are closed: the API now dispatches the framework events rather than logging separately, so there is still one writer; the disabled-account case is recorded directly because the framework has no event for "credentials were right but the account is switched off", which is the most interesting line on the screen; "sign out everywhere" — the lost-phone endpoint — records how far it reached; and every entry now names its door in words (**"from the mobile app"**, **"from the web dashboard"**) rather than the guard name, because an administrator reading this after an incident needs to know whether a phone was involved and `via sanctum` does not tell them. **A contact edit is deliberately not a security event** — only the sign-in address changing is recorded, because logging every new phone number would bury the one line that matters, and pointing the address at yourself is step one of taking an account over by password reset. **Being rate limited on the app is recorded too**, which it was not: the API answered 429 and said nothing, so somebody working through a password list produced a run of failed attempts that stopped dead at five with no line explaining why — reading like the attacker gave up rather than like the fence doing its job. Wiring that up surfaced a separate latent defect in `bootstrap/app.php`: the API's error renderer turned **any** `HttpResponseException` into a 500, so a response a caller had deliberately built to short-circuit with was discarded and replaced. Nothing had needed one until the limiter did |
| A1.9 | Session timeout + forced re-login policy | ✅ per-company idle timeout, off by default; resets on activity so long work is never interrupted, and a timeout is logged apart from a deliberate sign-out |
| A1.10 | Rate limiting on the password form | ✅ five wrong passwords per address per source per minute, then refused with the wait remaining. Counted on email **and** IP, so one person's mistakes cannot lock out a colleague behind the same office address. Raises Laravel's `Lockout` event rather than a bare 429, so every lockout lands in the audit trail and on the Security panel |

## A2. Company & Organization Setup
| # | Feature | Status |
|---|---|---|
| A2.1 | Company profile (name, logo, address, timezone) | ✅ |
| A2.2 | Office / branch management | ✅ |
| A2.3 | Office GPS coordinates + geofence radius | ✅ stored, and enforced when the company switches A4.16 on |
| A2.4 | Departments — CRUD & assignment | ✅ |
| A2.5 | Designations / job titles | ✅ |
| A2.6 | General settings page | ✅ |
| A2.7 | Company holiday calendar | ✅ |
| A2.8 | Weekend / working-days configuration per office | ✅ editable working week, company-level — the same definition leave charging, absence and the roster all read. A seven-day week is expressible; a zero-day one is refused |
| A2.9 | Attendance & leave policy rules engine | ✅ two halves, and the second landed 2026-09-21. The policies themselves are configurable — working week, reminder and auto-close windows, geofence, 2FA requirement, idle timeout, directory contact details, and **the default day**, which closed the last business rule in the codebase that no client could move: `determineStatus()` fell back to a literal 09:00–17:00 with 15 minutes' grace whenever no shift was rostered, so a company starting at six had its early shift judged against nine o'clock on every unplanned day and could never be recorded as late at all. Those are three company settings now — `default_day_start`, `default_day_end`, `default_day_grace_minutes` — **per company rather than per installation**, because on a multi-company box one client's ordinary morning is another's overtime; a default day may not run overnight and is refused with a reason if asked to. On top of them sits the **conditional rule builder** at `settings/rules`: *when* somebody clocks in or requests leave, *if* every condition holds, *then* notify a role, the line manager or the employee, and record it on the activity trail. Conditions are ANDed and there is deliberately no OR — an OR needs grouping, grouping needs parentheses, and two rules say the same thing legibly. **A rule never writes to attendance or leave**, only notifies and records: a rule that could change a punch's status would put a second, invisible author on rows payroll and a tribunal both read. The engine runs inside the path that records a punch, so every layer fails soft — a bad rule row, a deleted leave type, a mail server that is down — and the punch is written regardless. The vocabulary lives in one place (`PolicyRule::FIELDS`), so the form that offers a field, the validator that accepts it and the engine that evaluates it cannot drift; anything outside it is refused on save **and** skipped on read. Behind `manage-settings`, beside the policies, with no permission of its own |
| A2.10 | Multi-company (SaaS tenancy) support | ⬜ **not built — but its foundation is now tested rather than assumed.** `CLAUDE.md` records that "the schema is company-scoped throughout, so this is a routing and onboarding job rather than a data-model one". That claim is what the whole feature rests on, and it had never been verified. It holds, on both counts. **Schema**: 25 business tables carry `company_id`; the 17 that do not are framework tables (cache, jobs, sessions, migrations), `companies` itself, Spatie's role tables, or rows scoped through a user (`notifications`, `push_devices`, `personal_access_tokens`). **Queries**: `tests/Feature/CrossCompanyIsolationTest` stands up two whole companies and, as one company's administrator, attempts 36 real crossings — opening, editing and deleting the other company's employees, departments, designations, offices, shifts, holidays, leave types and announcements; publishing their announcement; deciding their leave; reading their employee's document vault and checklist; three API endpoints including the document download; and seven listings that must not merely refuse but must not *contain* the other company's rows. All refuse. **The suite was mutation-checked rather than trusted for passing first time**: removing the guard from `EmployeeDocumentController` and `DepartmentController` makes exactly the right three tests fail, one of them on a 200 for another company's document list. Three guard idioms are in use across the controllers — `authorizeCompany`, `authoriseCompany` and a bare `abort_unless` — plus ownership checks in self-service and team checks in the manager paths; reading each proves nothing about the next one somebody writes, which is why this is a test and not a review. **The row is mis-labelled and the remaining work is smaller than ⬜ implies** — the full working was in `Multi-Company_Tenancy-Assessment.md`, removed with the other client documents and recoverable from git history. Two companies can already be created (`emp:install --force`, or `--company-id=N` to attach an admin to an existing one), administered separately, and cannot see each other. What is left is **onboarding, one correctness fix, and a product decision**: creating a company is a command-line operation and there is deliberately no sign-up route, because a public "create your company" form on the client's own server would let anybody on the internet create tenants on it. **The correctness fix that had to come first is done**: the `?? Office::value('company_id')` fallback, repeated at 23 call sites, silently handed a user with no company whichever company owns the first office row — harmless on one company, a cross-tenant read on two. `companyId()` now lives once on the base `Controller` and fails closed, the 19 duplicated copies are gone, and three tests cover it, verified by restoring the old behaviour and watching a company-less admin get 200 on the dashboard. Also open, and recorded so it is a decision rather than a discovery: Spatie's `roles`/`permissions` carry no `company_id`, so all companies share one set — defensible, since the four roles and 19 permissions mean the same thing everywhere |

## A3. Employee Management
| # | Feature | Status |
|---|---|---|
| A3.1 | Employee CRUD + deactivate | ✅ deleting anyone who has ever clocked in is refused — `attendance_logs` cascades, so it would take the hours a finished payroll was calculated from. They are set to Terminated instead; deletion stays for records typed in by mistake |
| A3.2 | Assign department / office / designation | ✅ |
| A3.3 | Employment details (code, job title, hire date, status) | ✅ |
| A3.4 | Work mode — office / WFH / hybrid | ✅ |
| A3.5 | Login credential creation & reset | ✅ **Sign-in Account** panel on the employee page: create the login, set or generate a password (shown once, never stored readable), reset it, change the role, disable and re-enable. An employee record and a login are separate rows, so adding somebody to the payroll deliberately does not give them one |
| A3.6 | CSV / Excel bulk import | ✅ 11 columns incl. office/department/designation/manager, matched by name; whole file validated before anything is written, every problem reported at once; department required because it carries the shift; template download |
| A3.7 | Employee profile photo upload | ✅ JPG/PNG/WebP up to 2 MB; replacing one deletes the old file, and saving without one keeps what is there |
| A3.8 | Document vault (contract, ID, certificates) with expiry alerts | ✅ seven document types, held on the private disk and streamed through the app — never a public URL. Anything with an expiry date is chased to HR 30 days out, once per document, and deleting an employee takes their files with them |
| A3.9 | Emergency contact & personal details | ✅ contact name, phone and relationship, plus personal email, address, national ID and blood group |
| A3.10 | Org chart / reporting-manager hierarchy | ✅ printable nested tree from one query; anybody whose manager has left shows at the top rather than vanishing |
| A3.11 | Employee export (CSV / Excel) | ✅ same columns as the bulk import, in the same order, so an export can be edited and fed back in. Honours the filters on screen |
| A3.12 | Onboarding & offboarding checklists | ✅ company-standard steps with an owner and a due offset; raising a list **copies** them onto the person, so editing a template never rewrites history and deleting one leaves finished checklists intact. Every tick records who and when |

## A4. Attendance Management *(core module)*
| # | Feature | Status |
|---|---|---|
| A4.1 | One-tap Check In / Check Out (button-based) | ✅ |
| A4.2 | Server-authoritative timestamps (device clock never trusted) | ✅ |
| A4.3 | GPS + IP captured per punch (record-only, non-blocking) | ✅ |
| A4.4 | Duplicate-punch cooldown | ✅ |
| A4.5 | Auto in/out detection based on last punch | ✅ |
| A4.6 | Attendance log table (employee, office, type, time, status) | ✅ |
| A4.7 | Filterable, paginated attendance history | ✅ |
| A4.8 | Late / early-leave status vs assigned shift | ✅ |
| A4.9 | Daily summary (present / late / on leave / absent / headcount) | ✅ |
| A4.10 | Monthly attendance scoring (on-time %, late count) | ✅ |
| A4.11 | Weekly rollup summaries | ✅ one row per week — present, leave, absent, late, on-time % and attendance %. Weeks are clipped to the window so a short first week is reported as short. Schedulable by email |
| A4.12 | Manual attendance entry / correction by HR (with audit reason) | ✅ |
| A4.13 | Attendance regularisation requests (employee raises, HR approves) | ✅ |
| A4.14 | Overtime calculation & tracking | ✅ |
| A4.15 | Break in / break out punches | ✅ button on the employee portal, and the note under it now states **this shift's** break policy rather than a fixed sentence. It used to read "breaks are not counted as worked time" for everybody, which A5.7 made false for a paid break — see the note in that row |
| A4.16 | Geofence enforcement (block punch outside office radius) | ✅ off by default; exempts WFH/hybrid staff, offices with no coordinates, and punches that arrive without a location. Refusal names the distance |
| A4.17 | Auto-absent marking for missed days (scheduled job) | 🟡 absence stays derived; nightly job refreshes the scores that count it |
| A4.18 | Missing-checkout auto-close policy | ✅ closes at the scheduled shift end, marked `source: auto` |
| A4.19 | Live "who's in right now" board | ✅ four buckets that partition the roster — on the clock (breaks flagged), been and gone, on approved leave, unaccounted for. Refreshes each minute, pauses when the tab is hidden |
| A4.21 | QR check-in at an office screen (client requirement, 2026-09-30) | ✅ **Off by default** (`require_qr_checkin` on the Policies screen). A tablet, spare phone or TV at the office opens a **signed link** from *Attendance → QR Screens* (`manage-attendance`, so HR can do it) and shows a code; the employee's **own signed-in phone** scans it and `POST /attendance/qr` records the punch through the unchanged `AttendanceService::record()` — the server still decides in or out, lateness, work date, geofence and rules. **One code, one scan**: each code is a row claimed by a conditional update, so two people racing for one code get one punch and one "scan again"; the screen shows a fresh code within a second, and an unscanned one expires after 30 s, so a photo of the screen is dead on arrival. The punch is filed against the office **on the screen**. Once the policy is on, **office staff** are refused the button everywhere they have one — API `check` and offline `sync` (`qr_required`) and the web portal — while WFH and hybrid staff keep it; breaks stay a button for everybody. `source = 'qr'`, never `kiosk`, which is the demo-purge marker. **The client's "QR emailed on creation" is a one-time app sign-in code, not an attendance credential** (decided with the client): creating a login now sends a welcome email whose embedded PNG QR signs the phone in once via `POST /auth/activate` (7 days, replaced by a resend, device binding applies) and can never punch — a code sitting in an inbox can be forwarded, which is the buddy-punch the office screen exists to stop. *Send welcome email* on the employee page resends it. Email delivery still waits on SMTP (`MAIL_MAILER=log`). 34 tests (`QrAttendanceTest`, `ActivationTest`)  **"Open QR screen" (2026-10-06)**: a button on the dashboard banner and the QR Screens page lets Admin/HR pick an office and open its screen in a new tab — the office's screen is reused (same signed link, so a bookmarked tablet keeps working), or created as "{Office} check-in screen" on first open; a switched-off screen is never reused. The picker shows whether each office's screen is showing codes now, the link with Copy, and warns when the panel is on 127.0.0.1 (a tablet cannot reach it) |

## A5. Shift & Schedule Management
| # | Feature | Status |
|---|---|---|
| A5.1 | Shift creation (start, end, grace period) | ✅ |
| A5.2 | Shift assignment per department | ✅ |
| A5.3 | Weekly roster view | ✅ leave, holidays and company weekend aware |
| A5.4 | Shift-driven attendance validation | ✅ |
| A5.5 | Per-employee shift override | ✅ |
| A5.6 | Rotating / night shift patterns | ✅ |
| A5.7 | Break rule configuration | ✅ **each shift says what its break *means*, in two checkboxes.** `shifts.break_minutes` had always been a number with one hardcoded reading — unpaid, and deducted only as long as the break somebody actually punched — and both halves of that are company policy rather than arithmetic. **Break is paid**: nothing comes off, live or settled, and the shift's scheduled hours stop subtracting it too, because taking it out of one side and not the other would hand every employee on that shift a break's worth of overtime a day. **Deduct at least this much**: the shift's break is the *floor*, however short the punched one — which is what "an unpaid 30-minute lunch" normally means, and it closes the half of the incentive problem the old code left open. A day with no break punches already lost the nominal break, so before this a ten-minute lunch earned 20 minutes of overtime while the colleague who never touched the button earned 30; `overtimeFor` already named that incentive as the wrong one to build into a payroll figure, and this is the other end of it. **Both default false, which is exactly what every existing shift does today** — nothing about a live company's payroll moves until somebody ticks a box, and there is a test that says so. The policy is read in **one place** (`Shift::settledBreakDeduction`), which also removed a split brain: `workedMinutes` deducted the punched break and `overtimeFor` separately deducted the nominal one, so the rule lived in two functions that had to agree. `workedMinutes` now reports present time less only what the shift says comes off it, with the accounting split into `presentMinutes` and `punchedBreakMinutes` so the policy has somewhere to apply. **A live counter honours only the paid flag** — the nominal break has not been taken yet at five past nine, and a minimum cannot be judged until the break is over. **The policy reaches the person taking the break, on both halves — which it did not at first.** On the app, `/attendance/today` carried the shift's name, window and grace period but nothing about its break, so it computed hours correctly and could not say why; the three fields now travel with the shift and the Clock screen states the consequence under the button (see B2.6). On the **web portal the omission was worse than silence**: the page asserted flatly that *"breaks are not counted as worked time"*, which stopped being true the day a shift could mark its break paid — a false statement, on the screen where somebody decides whether to take one, addressed to the only person it mattered to. It now reads the shift's actual policy, and a paid break in progress says the clock is still running rather than *"worked time is paused"*, which would have been wrong in the most alarming direction. **Not built**: multiple named break windows, or a break the shift insists is taken between set hours. Nobody has asked, nothing in the schema wants it, and it would be a builder invented rather than needed |
| A5.8 | Roster drag-and-drop planner + publish to staff | ✅ **a palette of shift chips above the grid; drag one onto a day, or drag a day onto another to move it.** The design decision that makes it safe: **drag-and-drop writes into the selects that were already there and posts through the form that was already there** — no new endpoint, no new validation surface, and no second idea of what the roster says, because the select is the value and the chip is only a picture of it. Turn the script off and the planner is exactly what it was. That matters more than usual here: **HTML5 drag events do not fire on a touch screen at all**, so a planner that had replaced the selects would have quietly excluded anybody holding a tablet, and left no keyboard path either. The palette is `hidden` in the markup and unhidden by the script, because an instruction to drag something is worse than no instruction at all on a browser that cannot honour it. A day dragged onto another **moves** — copying instead would silently double a shift every time somebody fixed a mistake, which is the more expensive way to be wrong. Cells changed since load carry an amber edge, distinct from the Draft badge, which means something else entirely (planned but not published); leaving with unsaved changes warns, because the Draft badges on screen otherwise make it look as though something was kept. **Verified by driving a real browser**, not only by asserting markup: palette→day, day→day move, the Clear chip, and a drop onto the day it came from were each dispatched as real drag events, then the form was posted and the row read back out of MySQL. That pass found a genuine defect — the drop repaints the source cell and so destroys the element the drag started from, and `dragend` on a detached node never reaches the listener on `document`, leaving the cell somebody had just moved a shift *out of* stuck at 45% opacity, looking disabled, for the rest of the session. **Not built**: multi-select, drag across a whole row, and a modifier key to copy rather than move |
| A5.9 | Shift swap requests between employees | ✅ |

## A6. Leave Management
| # | Feature | Status |
|---|---|---|
| A6.1 | Leave types (annual, sick, unpaid, casual…) | ✅ |
| A6.2 | Leave request submission | ✅ |
| A6.3 | Multi-step approval workflow (manager → HR) | ✅ |
| A6.4 | Leave balance tracking & accrual rules | ✅ per-type: all at once, or a twelfth a month pro-rated from the hire date. The nightly job only ever raises a balance, so an HR adjustment is never undone |
| A6.5 | Leave history & status management | ✅ |
| A6.6 | Company leave policy configuration | ✅ types + holidays + weekend config, the default day alongside them, and since 2026-09-21 the rule builder — a leave rule can ask about the leave type, the length of the request, the department, and **days of notice**, which is the figure no column holds and the one every short-notice policy is written around. It is negative when leave is booked after it has already started, so `notice days is at most 0` catches backdated sick leave and leaves a fortnight's notice alone. See A2.9 |
| A6.7 | Team leave calendar / conflict detection | ✅ month grid, weekend- and holiday-aware, filterable by department. Pending is drawn alongside approved so cover is not granted twice onto one day |
| A6.8 | Leave ↔ attendance integration (leave day ≠ absent) | ✅ |
| A6.9 | Carry-forward & year-end processing | ✅ capped by the type (null uncapped, 0 off); an overdrawn balance starts at zero rather than in debt, and the roll is safe to run twice |

*Built so far: leave types, the employee self-service screen (balances, apply, withdraw),
weekend- and holiday-aware day counting, balance enforcement, the company-wide leave register
for Admin/HR, and the two-step approval chain — line manager, then HR. An employee with no
manager set goes straight to HR. Days are deducted only on final approval.*

*Approved leave now feeds attendance: it is reported as leave rather than absence on the
dashboard, the attendance overview and the department report, and the monthly score measures
absence against working days only. Weekends and company holidays count as neither.*

## A7. Reporting & Analytics
| # | Feature | Status |
|---|---|---|
| A7.1 | Attendance report (date range, office, department filters) | ✅ |
| A7.2 | PDF export | ✅ |
| A7.3 | Excel export | ✅ |
| A7.4 | Late employee report | ✅ |
| A7.5 | Attendance outlier report | ✅ |
| A7.6 | Department report | ✅ |
| A7.7 | Historical attendance analysis | ✅ |
| A7.8 | Company adherence overview | ✅ |
| A7.9 | Location & IP columns in exports | ✅ |
| A7.10 | Leave reports | ✅ days taken per employee split by the company's own leave types, pending days separately, and the year's unspent entitlement |
| A7.11 | Overtime reports | ✅ |
| A7.12 | Scheduled report email delivery (daily/weekly/monthly) | ✅ standing orders with a PDF or Excel attachment, sent at 07:00 in the company's timezone; recipients need no login; "Send Now" to test one. Needs a real `MAIL_MAILER` to leave the box **Fixed 2026-10-10: until then not one had ever left a real queue** — the attachment was held as raw bytes, the database queue stores jobs as JSON, and every run and every Send Now threw at the push; `Mail::fake()` hid it. Held base64 now, and `MailTemplatesTest` pushes one through the real database queue and checks the file arrives byte for byte. The mail itself is translated (en/es) |
| A7.13 | Custom report builder (pick columns + filters) | ✅ 18 columns over three groups, filtered by office, department, work mode and period; exports to PDF and Excel like the fixed reports |
| A7.14 | Payroll-ready export (hours worked per employee per period) | ✅ |

> **The report window is also the export's filename, and it used to be
> unvalidated.** `from` and `to` came straight off the query string and were
> concatenated into the name handed to `Excel::download()` and to dompdf — in
> `ReportController::handle` and again in `AttendanceController::reportData`. A
> filename is a path, and `maatwebsite/excel` wrote outside the configured disk
> when given a caller-controlled one through **3.1.69 (CVE-2026-84374, high)**,
> which is the version this project was pinned to. Both ends are now validated
> as `Y-m-d` at both call sites, and the dependency is on 3.1.70;
> `league/commonmark` went 2.8.3 → 2.10.1 in the same pass, clearing two
> medium advisories, and `composer audit` is clean. The validation is the half
> that stays true after the next upgrade — a controller that hands unfiltered
> request input to a file writer is one dependency bump away from the same hole.

## A8. Dashboard
| # | Feature | Status |
|---|---|---|
| A8.1 | Live tiles — present, late, absent, headcount | ✅ |
| A8.2 | Recent attendance feed | ✅ |
| A8.3 | Charts / visualisation | ✅ redesigned 2026-10-06 on the SmartHR dashboard layout, every figure from real rows: today's attendance donut (on time / late / on leave / absent, rate = present ÷ expected, null when nobody is), on-time-vs-late trend over 7 or 30 days, headcount by department. Charts read their colours from the theme and redraw when dark mode is toggled (`public/assets/js/dashboard-charts.js`) |
| A8.4 | Role-specific dashboards (Admin vs HR view) | ✅ admin opens on security and the trail; HR on approvals and expiring documents |
| A8.5 | Configurable widgets | ✅ thirteen panels (2026-10-06 added today's attendance, late arrivals with minutes late, employees by department, who's off soon with holidays, birthdays; tiles now compare with the last working day), chosen per user rather than per role; a panel the viewer lacks permission for is never shown, never offered and cannot be saved. A hidden panel costs no queries |
| A8.6 | Trend comparison (this week vs last week) | ✅ like for like — both windows run Monday to the same weekday, so a Tuesday is compared with a Tuesday rather than a finished week |

## A9. Notifications (Web)
| # | Feature | Status |
|---|---|---|
| A9.1 | In-app notification bell + centre | ✅ one inbox shared by the dashboard and the portal |
| A9.2 | Email notifications | 🟡 leave emails queued and rendering; MAIL_MAILER still `log` |
| A9.3 | Late-arrival alert to HR | ✅ one digest a day naming everybody and how late they were — not an alert per person. Silent on a day with no lateness |
| A9.4 | Leave request / approval / rejection alerts | ✅ routed by NotificationService, both stages |
| A9.5 | Schedule update alerts | ✅ publishing a roster tells each affected employee once, covering the whole range rather than one message per day. In-app, email and push |
| A9.6 | Missing-checkout reminder | ✅ sent once, a configurable grace after the shift ends |

## A10. Manager Workspace *(team leads)*

> A parallel area at `/manager/*`, gated `role:manager` **and**
> `permission:view-team`. Not part of the admin app: that group is wrapped in
> `role:admin|hr`, which refuses a manager whatever permission they hold, so the
> role can only be reached by a group of its own.
>
> Scope is `employees.manager_id` — **direct reports only**, resolved by
> `App\Services\ManagerScope`, which every query goes through. No new tables:
> the reporting line already existed. Leave approval is a single hop (manager →
> HR), so the scope deliberately does not recurse down the tree.
>
> Managers are read-only outside approvals. Correcting attendance is
> `manage-attendance`, planning the roster is `manage-shifts`, exporting is
> `export-reports`, and HR-grade PII sits behind `manage-employees` — none of
> which this role holds, and none of which this area asks for.

| # | Feature | Status |
|---|---|---|
| A10.1 | Manager dashboard — team, today, and what needs deciding | ✅ tiles (team, on the clock, present, late, on leave, unaccounted), leave and swaps waiting on *this* manager, missing clock-outs from finished days, 7-day published coverage with unplanned working days flagged, today's team list and recent punches. Every figure counted from real rows; nothing is placeholder |
| A10.2 | Team list + search | ✅ direct reports with today's status, shift and worked hours; searchable by name, code or department |
| A10.3 | Team member detail | ✅ employment facts, leave balances, upcoming published shifts, 30 days of attendance and leave history. Personal PII deliberately excluded |
| A10.4 | Team attendance board (any past date) | ✅ same status vocabulary as the employee's own history and the app — one `dayStatus`, so nobody reads two different words for one day |
| A10.5 | Team punch log | ✅ filterable by person, date, type and status. An employee id outside the team narrows to nothing rather than reaching past the scope |
| A10.6 | Team schedule (published roster) | ✅ fortnight grid, leave outranking a rostered shift, drafts invisible exactly as they are to staff |
| A10.7 | Team approvals inbox | ✅ the existing controller in the manager shell; leave and shift swaps, with clashes flagged. Writes stay on the one portal endpoint |
| A10.8 | Team-scoped reports | ✅ Late Arrivals, Overtime and Weekly Rollup through the same `ReportService` and the same results partial the HR reports use, handed the team as an id list. Print only — no PDF/Excel, which is `export-reports` |
| A10.9 | Manager notifications | ✅ already routed by `NotificationService::approversFor` — a request awaiting a manager goes to that manager. No new events, deliberately: a notification per punch would be spam |
| A10.10 | Manager role on mobile | ✅ no change needed — the app derives manager mode from `permissions`, which `/auth/me` already returns, and `/team/attendance` and `/team/roster` are unchanged |
| A10.11 | Roster editing / attendance correction by managers | ⬜ by decision — see the note above |

---

# PART C — SHARED BACKEND & API

| # | Feature | Status |
|---|---|---|
| C1.1 | **Laravel Sanctum token auth + `routes/api.php`** | ✅ |
| C1.2 | `/auth/login`, `/auth/logout`, `/auth/me` (+ `logout-all`, `devices`) | ✅ |
| C1.3 | `/attendance/check`, `/attendance/break`, `/attendance/sync`, `/attendance/history`, `/attendance/today`, `/attendance/regularisations` | ✅ same AttendanceService as the web button — one set of punch rules. `today` reads clocked-in state from `breakState`, not from the last punch: `break_end` is neither `in` nor `out`, so the old reading would have offered "Check In" to somebody who never left |
| C1.4 | `/leave/*` endpoints | ✅ balances, apply, list, withdraw + the manager inbox — all via LeaveService |
| C1.5 | `/schedule`, `/profile`, `/documents` endpoints | ✅ published roster only, leave/holiday/weekend aware; profile read + contact edit + password, plus `PUT /profile/details` for the employee record's own address and emergency contact (B3.2); own documents listed and streamed, read-only |
| C1.6 | Device token registration for push | ✅ register/list/unregister; cleared on sign-out. Delivery is Phase 5 |
| C1.7 | API rate limiting + throttling | ✅ per-user limiters — 120/min ceiling, login 5, punch 20, writes 30 |
| C1.8 | Consistent JSON error format + API versioning (`/api/v1`) | ✅ one shape for every failure, derived from the status rather than from which exception class happened to be thrown, so one condition never arrives under two names. **One hole was closed on 2026-09-15**: the renderer turned any `HttpResponseException` into a generic 500, discarding a response the caller had deliberately built to short-circuit with. Nothing in the codebase had needed one, so it went unnoticed until the login rate limiter's own `response()` callback did — and the effect would have been a 500 on every future use of that pattern. Such a response is now returned as raised, and the throttled 429 that goes through it is pinned to the standard shape by a test |
| C1.9 | Queue worker + scheduler (reminders, auto-absent, reports) | ✅ **installed and running** on `hrams.devonlinetestserver.com` — `emp:preflight` reports the scheduler last ran seconds ago and the queue empty with no failed jobs. Cron-driven rather than systemd (`HOSTING_MODE=managed`, `deploy/emp-webspace.cron`), because the host is managed webspace with no systemd; that setting also widens the preflight tolerance from five minutes to fifteen, which is normal for cron and alarming for a daemon. Queued notifications survive a deleted record and retry a bad send. Nothing they send leaves the box while `MAIL_MAILER=log` |
| C1.10 | Immutable audit log for attendance records | ✅ punches are append-only (edit/delete refused); every write records actor, source, IP and a full snapshot |
| C1.11 | Automated test suite (feature + unit) | ✅ 1366 server tests covering attendance, leave, roster, swaps, the API, the audit trail, password reset, push, backups, install, employee import, preflight and the manager role, plus 232 in the app — models, formatting, offline behaviour, the biometric lock, the gate, crash reporting, accessibility, the translations, and the four screens that must not build a date from the handset's clock |
| C1.12 | API documentation (Scribe / OpenAPI) | ✅ `API-Reference_v1.md`, kept honest by a test that walks the route table |
| C1.13 | Database backup & restore strategy | ✅ `db:backup --verify` nightly — dumps, restores into a scratch database to prove it reads back, then rotates |
| C1.14 | Production deployment (HTTPS, env hardening) | ✅ **live at `https://hrams.devonlinetestserver.com`** — managed webspace, cron-driven queue and scheduler (`HOSTING_MODE=managed`), TLS clean, `/api/v1/ping` answering `{"ok":true,"service":"KEMP"}`, and `/privacy` and `/account-deletion` both reachable logged out, which is what the two stores fetch. Updates ship with `ALLOW_NON_PRODUCTION=1 bash deploy/deploy.sh` — the flag because the box's `.env` says `staging` and the script refuses to guess which database to migrate. **`emp:preflight` is not green on it and should not be read as if it were**: the demo quick-login panel and the seeded `password` accounts are live on a public URL, `MAIL_MAILER` is still `log`, and `TRUSTED_PROXIES` is unset behind Varnish so every punch records the proxy's IP rather than the employee's. **Preflight now also gates on dependency advisories** — `composer audit` runs inside the command, a critical or high advisory fails the deploy and medium or low warns, so no release ships past a known hole without somebody deciding to. It never fails because it *could not* look: the advisory database is fetched over the network, and no composer, no network or a timeout all warn and say which rather than turning "I could not check" into "you may not deploy". This closes the dependency register's finding 13.6, which had asked for exactly this and sat open long enough for the `maatwebsite/excel` CVE to arrive unnoticed. **The escape hatch is no longer all-or-nothing, and the next deploy to this box will stop.** `ALLOW_NON_PRODUCTION=1` used to run preflight as `|| echo "(advisory)"`, which downgraded every check in the command — so the seeded `password` accounts, the unset `TRUSTED_PROXIES` and an empty `APP_KEY` all printed red and the deploy said "Done" anyway. It now passes `--non-production`, which downgrades only the failures that can be a deliberate choice on a demo box (mail to the log, the quick-login panel) and never the five that cannot: `APP_KEY`, `Database`, `Demo credentials`, `Dependency advisories`, `TRUSTED_PROXIES`. Two of those are currently failing on this box, so the next run will refuse until the `.env` is fixed — which is the point, and is the change to be ready for rather than surprised by. |
| C1.15 | Push delivery to handsets (FCM v1) | ✅ channel alongside database and mail; silent until a service-account key is configured; deletes handsets FCM reports UNREGISTERED (or INVALID_ARGUMENT only when the token itself is the bad field — a malformed message never deletes handsets); a 429/5xx/timeout/stale access token re-sends to that one handset via `RetryPush` (1, 5, 15 min, honouring Retry-After), then lands in failed_jobs |
| C1.16 | Public privacy policy + account-deletion pages | ✅ no login required — both stores demand it before an app with accounts is listed |
| C1.17 | Real-install setup, no demo data | ✅ `emp:install` creates the company and first admin, or attaches an admin to an existing company (`--company-id`); validated timezone, roles seeded, one transaction. `db:seed` now makes roles only. `emp:purge-demo --dry-run` clears a seeded database and names the real rows the cascade would take with it **US-only since 2026-10-10:** `emp:install` and the Company page offer the eight US zones and USD and nothing else (a company already saved on another zone or currency keeps it on the form) |
| C1.18 | API messages in the caller's language | ✅ **English and Spanish, and not one line of the app changed** — `ApiErrorText.text` already showed the server's words as they arrived, which is what the header shipped in B6.2 was for. `SetApiLocale` reads `Accept-Language` on the API group only and **fails open at every level**: an unknown language, a malformed header, a `q=0`, none at all, all answer in English rather than refusing, because a preference is not a credential. Region is dropped — `es-MX` and `es-419` are `es`. Every message, every validation error (Laravel's own `validation.php`, translated whole and checked against the framework's so an upgrade that adds a rule fails the suite), the leave stage, the geofence refusal, and the meridiem on a pre-formatted punch time. **Notifications are the half a request cannot decide**, and the reason `users.locale` exists: a worker renders them with no request and no header, usually because of somebody else's action — HR approving leave in English decides what an employee reads in Spanish — so the language has to be a fact about the recipient, which `User::preferredLocale()` supplies to push, the notification centre and the email alike. Leave types, office names and the maintenance message stay as typed: they are data, not vocabulary **Mail, 2026-10-10:** dates in leave, checkout, schedule and late-arrival mails are now in the recipient’s language (`translatedFormat`), the hard-coded English "to" and the Spanish footer are fixed, and `Markdown::withSecuredEncoding()` stops a leave reason, decision note or document title becoming a working link in a mail we send |

---

# PART D — AI HR ASSISTANT *(later phase)*

| # | Feature | Status |
|---|---|---|
| D1.1 | Natural-language HR queries ("who was late last week?") | ⬜ |
| D1.2 | Attendance history search | ⬜ |
| D1.3 | Leave history search | ⬜ |
| D1.4 | Auto-generated attendance & department summaries | ⬜ |
| D1.5 | Context-aware follow-up questions | ⬜ |
| D1.6 | **Permission-aware answers** (never leaks data above the asker's role) | ⬜ |
| D1.7 | Available in both web dashboard and mobile app | ⬜ |

---

# Delivery Roadmap

| Stage | Contents | Status |
|---|---|---|
| **Stage 0** | A1–A5, A7, A8 (minus gaps) | ✅ Phase 1 web dashboard, live and verified |
| **Stage 1** | C1 — Sanctum API layer | ✅ Done — the hard blocker is gone |
| **Stage 2** | A6 — Leave Management (web) | ✅ Done — no accrual engine, no calendar view |
| **Stage 3** | A9 + C1.9 — Notifications + scheduler | ✅ Built — `MAIL_MAILER` is still `log`, so no mail leaves the box |
| **Stage 4** | A5.5–A5.9 — finish Shift & Schedule | ✅ Done — planner is a grid, not drag-and-drop |
| **Stage 5** | B1–B3 — Mobile app v1 (login, punch, self-service) | ✅ **Closed out.** The screens work, punches carry GPS, the app is usable offline, and the handset can be held behind its own biometric check. Every row this note used to list as open has since shipped — device binding (B1.6), the on-phone geofence warning (B2.5), mock-location detection (B2.7) and now the launcher quick action (B2.8) — so there is nothing left across B1–B3. The calendar grid and the personal score (B3.4, B3.5) shipped earlier, and so did the address and emergency-contact fields, which had no endpoint to write to until `PUT /profile/details` |
| **Stage 6** | B4–B5 — Leave + push in app | ✅ Leave and push both done. Push is silent until the Firebase project exists — console work, not code. **The one code exception is closed**: B5.4's `route: schedule` is now parsed by the app (`PushRoute.schedule`), so a roster notification lands on the roster rather than nowhere in particular |
| **Stage 7** | A4.12–A4.15, A7.10–A7.14 | ✅ Attendance depth + reporting — correction, regularisation, overtime, break punches, payroll export, leave reports, scheduled delivery, report builder |
| **Stage 8** | A1.7–A1.9, A2.3, A2.8, A4.16 | ✅ 2FA, the security trail, the idle timeout, the working-week editor and geofence enforcement |
| **Stage 9** | A3.7–A3.11, A6.4/A6.7/A6.9, A4.19, A9.3 | ✅ Photos, the document vault, emergency contacts, the org chart, the roster export, leave accrual and carry-forward, the leave calendar, the live board and the late-arrival digest |
| **Stage 10** | A3.12, A4.11, A8.4–A8.6, A9.5 | ✅ Dashboards per role and per person, week-on-week trends, weekly rollups, schedule alerts and on/offboarding checklists |
| **Stage 11** | B7 — Manager mode in the app | ✅ Team roster added; approvals and team attendance already shipped |
| **Stage 12** | A10 — Manager workspace on the web | ✅ The manager promoted from a tab on the employee portal to a first-class role with its own area, dashboard, team screens, scoped reports and approvals inbox. One scope service, no new tables, no mobile change |
| **Stage 13** | D1 — AI HR Assistant | ⬜ Out of scope for now, by decision. Needs mature data across attendance + leave |
| **Stage 14** | The API catches up with the web | ✅ `/attendance/break`, `/documents`, `/attendance/regularisations`, `/directory`. Four features the web had shipped and the API had never exposed — every one of them had a status note naming an internal dependency that had long since been met |
| **Stage 15** | The app catches up with the API | ✅ `PushRoute.schedule`, profile editing, the break button, My documents, Corrections and Colleagues. Every endpoint the API offers now has a screen behind it |
| **Stage 16** | Roles behave the same on both halves | ✅ The Team-tab gate now needs the permission *and* a team, so HR and report-less managers no longer get an empty area. HR is desk-only by decision — see below |
| **Stage 17** | Reliability — offline and biometrics | ✅ **B2.4 offline punch queue, B6.3 offline cache and B1.3 biometric unlock.** The app opens, reads and clocks with no signal, and a shared handset can be held behind its own fingerprint or face check without the phone ever becoming the only way in |
| **Stage 18** | Store readiness | ✅ **All six rows done** — B6.6 the force-update and maintenance gate, B6.5 crash reporting, B6.4 the accessibility audit, B5.6 the notification centre, B1.1 the onboarding carousel and B6.2 multi-language. Five of them carry a server half or a test suite behind them rather than a document that goes stale: a preflight check, an administrator's crash screen, a history endpoint, an accessibility file that measures contrast and pumps six screens at 2× text, and a locale file that reads gen_l10n's own report of what is still untranslated. The library decision for B6.2 was Flutter's own `gen_l10n` and nothing else, for the same reason there is no crash SDK: four documents say this app carries nothing that talks to a third party |
| **Stage 19** | The submission bundle itself | ✅ **Five defects that no test could see, because none of them is code that runs.** `PrivacyInfo.xcprivacy` was a file in a folder and not in the iOS target, so every build shipped without it and Apple auto-rejects on that alone. `ACCESS_FINE_LOCATION` implies a **required** GPS `<uses-feature>`, which had been quietly filtering the Play listing off every device without the hardware — an app nobody could find rather than one that failed. The 512×512 Play icon was a crop of one corner of the mark. There was no 1024×500 feature graphic, which Play requires on every listing. And the adaptive icon had no `<monochrome>` layer, so a themed Android 13+ home screen left this app the one orange tile in a recoloured grid. All five verified in a real `bundleRelease` and against the merged manifest. Also written down for the first time: the reviewer sign-in account both consoles demand for a login-only app, and the console forms — content rating, app access, EU trader status, Play's 12-tester closed test — that block a release while the code sits finished. The app is also now declared **iPhone-only** — `TARGETED_DEVICE_FAMILY = 1` in all three configurations and no `~ipad` orientation key — which drops the 13" iPad screenshot set and the reviewer opening it on hardware nobody has laid it out for. See `Store-Submission_Checklist.md` |
| **Stage 20** | KEMP brand mark | ✅ The client's icon set applied across both halves. The supplied artwork is a **pre-rounded plate on transparency**, which is the wrong shape for a launcher and illegal for Apple — an alpha channel on an App Store icon is a rejection — so two masters are derived from it rather than handing it over as-is: an opaque full-bleed square for iOS and legacy Android, and the mark keyed off its navy for the Android adaptive foreground, sitting on `#052C6E` so any navy missed at an anti-aliased edge is invisible against the layer beneath it. `flutter_launcher_icons` regenerates every density from those two, then **silently rewrites `ic_launcher.xml` and drops the `<monochrome>` block** — it has no themed-icon support — so that layer is restored and verified inside the built `.aab`, not just in the source tree. The three store images are cut from the same master by hand, since that command does not touch them. On the web the sidebar, header and collapsed marks and the favicon all move from the Klutch Cleaning company logo to the KEMP lockup, with a white-wordmark variant for the dark sidebar — recoloured from the divider rightwards only, because whitening the whole lockup fills the plate solid and swallows the K inside it. Eight hardcoded `alt="Klutch Cleaning"` strings now read `config('app.name')`. The app itself is renamed **KEMP** — the Android label, both iOS bundle-name keys and `appTitle` in both ARB files, where Spanish carries the identical string because a brand is not translated; `locale_test.dart` already exempts rows under 20 letters for exactly that reason, so the suite stays honest rather than being loosened for it. The bundle id `com.hrms.attendance` stays as it is: not user-visible, and unchangeable after a first upload. **The app's interior stays brand orange** by decision: that palette is what `accessibility_test.dart` measures at 4.5:1 on every surface in both themes, and rethemeing means redoing that audit, not swapping a constant |

### Roles on the phone (Stage 16)

The web separates the four roles properly. The app was written employee-first
and grew a manager tab, and the seams showed in three places. All three are now
closed — the last of them, the administrator empty state, on 2026-09-18.

| | What the app gives them | Right? |
|---|---|---|
| **Employee** | Clock, History, Leave, Schedule, Profile, My documents | ✅ |
| **Manager** | The above plus Team — approvals, team attendance, published roster, who is off this month | ✅ |
| **HR** | The employee screens only — **desk-only by decision**, see below | ✅ the behaviour was always right; **the demo data was not, until 2026-09-16.** `hr@emp.test` had a user and a role and no employee record, so on a handset it landed on the admin empty state on all four employee screens — this row said one thing and the seeded account did another, and nothing failed to say so. HR now carries EMP-0006, reporting to nobody. `tests/Feature/Api/DemoRoleAccessTest` pins all four roles |
| **Admin** | Signs in, then is told why on every employee screen — no employee record | ✅ **was ⚠️ "by design, but poorly explained", and the explaining is now done.** Each of the seven screens already carried its own sentence and dropped its retry button, which is the half that was right. The half that was not: `AsyncView` drew `Icons.cloud_off` above all of them, so an administrator opening the app was told the network was down, seven times, directly above a sentence saying it was not — the picture and the words disagreed and the picture is what gets read first. `AsyncView` now takes `permanent`, which owns the icon **and** the retry suppression, so the rule lives in one place instead of being restated as `onRetry: _fatal ? null : _load` at seven call sites. Underneath it, the API stopped answering this with the generic `forbidden`: that code also means "that leave request is not yours" and "that correction is not yours", so the app's classifier was relabelling two ordinary, recoverable refusals as a permanent account defect and stripping the retry that would have cleared them. `App\Exceptions\NoEmployeeRecord` gives it `no_employee_record` of its own, and `test/no_employee_record_test.dart` now pins all four claims — message, no retry, right icon, and `forbidden` **not** treated as this — across all seven screens rather than three |

**Fixed:** the Team tab hung off the `approve-leave` permission alone. HR holds
that permission — it is the second step of the approval chain — and almost never
has direct reports, while every endpoint behind the tab is scoped to direct
reports. So HR got a Team tab whose every screen was empty, permanently, with
nothing explaining why; so did any manager with nobody reporting to them. The
web never had this, because `/manager/*` is gated `role:manager` **as well** and
refuses HR at the door. The tab now needs the permission **and** `is_manager` —
a field `/auth/me` had always returned and the app had always parsed and never
read. "manager is a role *and* a relationship, and both must line up."

**Decided: HR is desk-only, and that is the intended behaviour, not a gap.** HR
signs in and gets the employee screens — they clock in and book their own leave
like anybody else — and nothing more. Everything HR actually does is employee
records, the leave register, reports and the document vault, none of which is a
phone job; the company-wide stage-two approval queue stays on the dashboard.

So there is deliberately **no HR approvals inbox in the app**, and a future
reader should not restore one thinking it was forgotten. If the client asks for
phone approvals later it is an additive change — a company-wide endpoint gated
on the `hr` **role** rather than the `approve-leave` permission, which managers
share — and not a rework of anything here.

### The thing that used to gate the rest, and what replaced it

**C1.14 is done.** The app is live at `https://hrams.devonlinetestserver.com`.
A handset could not resolve `127.0.0.1`, FCM would not call back a laptop, and
neither store accepts a privacy-policy URL pointing at localhost — all three are
now answered by a real domain with a clean certificate. Store submission and
real-device testing are unblocked.

**What gates the rest now is three settings on that box, not code.** Every one
of them is a line in `.env` and a `config:cache`:

1. **The demo quick-login panel is ON at a public URL, and `admin@hrms.test`
   still has the seeded password.** One click on the login page is full admin —
   employee records, the document vault with passport scans, every punch. This
   is the one to fix today; the rest can wait.
2. **`MAIL_MAILER=log`.** Password resets, leave decisions, scheduled reports
   and document-expiry warnings are all built, tested and queued, and all go
   nowhere.
3. **`TRUSTED_PROXIES` is unset behind Varnish**, so `attendance_logs` is
   recording the proxy's address on every punch. The audit trail (C1.10) and the
   IP column in exports (A7.9) are both quietly wrong. **It no longer goes
   unsaid**: `DetectUntrustedProxy` notices a forwarded request arriving while
   nothing is trusted, and `emp:preflight` turns that sighting into a FAIL that
   names the header, the address the proxy claimed and the one actually stored.
   The setting is still the fix, and it is still an `.env` line on the box — but
   a deploy now refuses rather than passing quietly.

Push (B5) additionally needs the Firebase project, which is console work rather
than a setting. See `Deployment-Guide_Production.md` and
`Store-Submission_Checklist.md`.

---

## Counts across both repositories

Kept whole here because the total is the number a client asks for, and half a
total answers nothing. The per-half breakdown is in the header of each file;
this is the sum.

| Area | Built | Partial | Planned | Total |
|---|---|---|---|---|
| Web Dashboard (A) | 102 | 2 | 2 | 106 |
| Mobile App (B) | 57 | 0 | 0 | 57 |
| Backend / API (C) | 18 | 0 | 0 | 18 |
| AI Assistant (D) | 0 | 0 | 7 | 7 |
| **Total** | **177** | **2** | **9** | **188** |

**The web dashboard is complete, AI excluded.** Stages 8 through 12 are all
delivered. Two planned rows and two partial ones remain across Part A, and none
of them blocks a production deployment. Part B (mobile) has no open row
left. The AI assistant (Part D) is deliberately out of scope.

**The two partial rows left are both waiting on something other than code:**
A9.2, which is email and waits on SMTP credentials, and A4.17, where absence
stays derived rather than written — a decision rather than a gap.

**Still open, and worth being explicit about:** multi-company tenancy (A2.10),
and roster editing and attendance correction by managers (A10.11 — held with
`manage-shifts` and `manage-attendance` on purpose). **The conditional rules
engine (A2.9, A6.6) has come off this list**: it was the last row that was
neither built nor parked by decision, and it shipped on 2026-09-21.

**The 2FA QR (A1.7) is done, and the note that said it was blocked was wrong.**
It had been recorded as waiting on composer, which supposedly could not resolve
a new dependency because of a `league/commonmark` advisory. Composer resolved
`bacon/bacon-qr-code` without complaint on the first attempt. A blocker nobody
re-tested outlived the thing blocking it — worth re-reading any note of that
shape against the tool rather than trusting it.

**The break policy (A5.7) is done**, but read the row rather than the tick:
what shipped is *what the shift's break means for paid time* — paid or unpaid,
and whether the shift's figure is a floor — not a builder of multiple named
break windows. That last was left out deliberately: nobody has asked for it,
nothing in the schema wants it, and it would be a feature invented rather than
needed.

**The team leave calendar (B4.6) is done**, and it was the fifth instance of
the same pattern: the web had shipped the feature (A6.7) and the API had never
exposed it. `GET /team/leave-calendar` and an "Off" tab in the manager's Team
area. It landed behind the manager's gate rather than on the employee's Leave
screen — see the row for why, which is a disclosure decision and not a
placement one.

**The web dashboard is English only, and deliberately so.** It is HR's and the
administrator's screen; the workforce that needed Spanish is the one holding the
phone. Every message the two halves share is a `__()` call now, so the strings
are already there — what a Blade pass would still cost is the templates
themselves, and nobody has asked for it.

**Two smaller things a Spanish reader still meets in English**, both by
decision: `/privacy` and `/account-deletion`, which are web pages the app links
out to; and the leave types, office names and designations a company typed in,
which are renamed rather than translated.

**Two things are code-complete but inert until configured**, and neither is a
code change: `MAIL_MAILER` is still `log`, so no email leaves the box; and push
stays silent until a Firebase project exists.

**Four app rows were marked blocked on web work that has since shipped**, and
the notes have been corrected in place — B2.6, B3.7, B5.4 and B7.2. In every
case the real gap turned out to be the same one: the web has the feature and the
API never exposed it. B5.4 was the exception — the server sent that push and the
app's `PushRoute` enum had never heard of it — and it is now closed too, so all
four read ✅. A row that names an *internal* dependency goes stale the day that
dependency ships, and nothing fails when it does, so re-read these against the
code rather than trusting the note. **This paragraph is its own example**: it
described B5.4 as still open for a while after the enum row landed.

---

*Updated 2026-09-14 from the live codebase — `hrms/` and `mobile/` both read directly
rather than from the previous edition of this file. Superseded the stale build-status
section of `Phase-1_Admin-Dashboard_Attendance_SOW.md`, since removed with the other
client documents.*

*Last verified 2026-09-22, after the HR area: `php artisan test` **1561 passed
(3838 assertions)**, `flutter analyze` clean, `flutter test` **327 passed**.
The thirty-three new server tests are `HrLeaveDeskTest` (19) and
`HrEmployeeRegisterTest` (14); the ten new app tests are `hr_area_test.dart`.
Both server files lead on the gate rather than on the feature — a line manager
holds `approve-leave` and must not reach the step that spends the days, and must
not read an employee record even for their own report.*

*Two things this work walked into, both already written down and both worth
re-reading before the next change:*

*1. **Trap 1, for the fifth time.** `whereBetween('work_date', …)` in the
attendance summary returned nothing on SQLite — a `date` cast is stored as a
midnight timestamp and the string compare drops the last day of every range.
`AttendanceLog::forDates()` is the fix, and the note above said to assume a
fifth instance was waiting.*

*2. **A filled button inside a `Row` throws**, because the app theme sets
`minimumSize: Size.fromHeight(50)` — a width of infinity — and a Row's main axis
is unbounded, so that becomes a tight infinite width. The manager's approval
card had already overridden `minimumSize` for this reason, which is why it had
never surfaced. Caught by a widget test rather than by review.*

*`flutter test` failed once on `locale_test` during a parallel run and passes
alone and with `-j 1`. That test waits a fixed 100ms inside `runAsync` for a
disk read, and one more test file in the run was enough to occasionally outlast
it. Timing, not behaviour — but it is now flaky enough to be worth a real
condition rather than a sleep.*

*Last verified 2026-09-21, after the rule builder: `php artisan test` **1528
passed (3708 assertions)**, `flutter analyze` clean, `flutter test` **317
passed**. The fifty-three new server
tests are `RuleEngineTest` (25 — what a rule does, including that a bad one
costs the notification and never the punch or the booking) and
`PolicyRuleScreenTest` (28 — what may become a rule, which is the half that
decides whether the engine is ever handed something it cannot read). **The app
count is unchanged because no Dart was touched**: a rule is evaluated where the
punch is written, which is the server, and the app has no rules screen — the
notification it receives is an ordinary one.*

*The cross-company lookup refusal is mutation-checked: replacing the id check in
`PolicyRuleController::cleanConditions` with `true` fails exactly one test, the
one that posts another client's department.*

*Last verified 2026-09-21: `php artisan test` **1475 passed (3596 assertions)**,
`flutter analyze` clean, `flutter test` **317 passed**, `composer audit` clean.
The fourteen new server tests are `UnrosteredDayPolicyTest` — the default day,
see A2.9. **The app count is unchanged because no Dart was touched**: the
default day is read where status is decided, which is the server, and the app
has never named a shift time of its own.*

*Earlier the same day, before that change: 1461 passed (3565 assertions), which
was the branch `fix/admin-empty-state-and-proxy-detection` read as it stood —
three commits ahead of `main` and not yet merged. Those three carry their own
tests: `UntrustedProxyTest` on both halves of the proxy work,
`BreakThresholdTest` and `LateDayCountTest` on the server, and
`history_punch_count_test.dart` on the phone.*

*2026-09-17: `php artisan test` **1420 passed (3498 assertions)**,
`flutter analyze` clean, `flutter test` 290 passed. The nine new tests are the
page-size pair — see the note at the end of this file. The app count is
unchanged because no Dart was touched.*

*2026-09-15: 1411 passed (3472 assertions). B4.6, B3.2, A5.7, A5.8 and
the 2FA QR were finished across that stretch; the last two screens that still
built a date from the handset's clock — attendance history and the team roster —
were fixed; and **the security trail was found to be silent about the entire
mobile app and about every self-service password change on both halves**, which
is now closed. See A1.8.
The A5.7 migration was run against the real `emp` MySQL database as well as the
in-memory sqlite the suite uses, and A5.8's drag-and-drop was exercised in a
real browser against that database rather than only asserted in markup.
The same pass closed **B1.6 device binding, B2.5's geofence warning and B2.7
mock-location recording**, and stood up `CrossCompanyIsolationTest`.
**B2.8, the launcher quick action, went in after that** and took the last
buildable ⬜ off this list with it — everything still marked ⬜ is now parked by
decision rather than outstanding. Its 18 tests are why the app count is 290
rather than the 272 the line above used to read, and `flutter build apk --debug`
was run to check the merged manifest per trap 32: `quick_actions` adds no
permission.*

*Also 2026-09-15, and not a feature: **`TRUSTED_PROXIES` had never been read.**
It was configured in `bootstrap/app.php`, whose `withMiddleware` closure runs
before Laravel loads .env, so the guard around it was false on every box in
every environment since the file was written. Behind the proxy that means every
punch recorded the proxy's address rather than the employee's, the IP column in
exports was one value repeated, and the login rate limiter — which keys on the
address — turned A1.10's "one person's mistakes cannot lock out a colleague"
into the opposite. Moved to `config/trustedproxy.php`, where the framework's own
middleware reads it and where `env()` survives `config:cache`; `emp:preflight`
now reads the same key rather than `env()`, so it cannot report "unset" on a box
that had just set it. Five tests, and honestly labelled: four of them would have
passed against the broken build, and only the fifth covers the fix. See trap 36.*

*2026-09-16, and the same shape of problem in the demo data rather than the
code: **`hr@emp.test` had no employee record**, so two of the four roles could
not be shown on a handset — HR landed on the administrator's empty state on all
four employee screens, contradicting the row above and the brief written for the
UI designer, with nothing failing to say so. HR now carries EMP-0006 and reports
to nobody; admin still deliberately has none, because that refusal is a designed
screen. `tests/Feature/Api/DemoRoleAccessTest` pins what each of the four roles
gets on the phone, and removing the new record fails exactly the three HR tests.*

*2026-09-25, the same symptom again on staging, from a different cause: the
record existed but had lost its login. `employees.user_id` is `nullOnDelete`, so
a demo user deleted and recreated leaves EMP-0006 with no `user_id`, and
`firstOrCreate` finds the row and changes nothing — a re-seed could not repair
it. `DemoDataSeeder::relinkIfCutLoose` now fills that gap and only that gap: a
row linked to somebody else, or a user already somebody's employee, is left
alone. Two tests; the relink one fails against the old seeder. Staging needs a
deploy and `db:seed --class=DemoDataSeeder --force` to pick it up.*

*2026-09-29, a US client, and the company's timezone made to govern everything.
The zone is chosen on the Company page (US zones first). An audit then found the
server's UTC clock leaking through in four ways, all fixed and pinned by
`CompanyTimezoneCorrectnessTest`, which runs a New York company at 10:00 and at
21:30 — every other suite's company is UTC, where the mistakes cancel out:
punches are stored as the company's wall clock, and the **manager panel
converted them again**, printing every punch four hours early; "today",
"this month" and "this year" defaults came from `now()`, so from 8pm Eastern the
dashboard, leave lists, roster and calendar were on tomorrow; the late-arrivals
digest ran at 10:30 UTC (06:30 in New York); and real-UTC stamps (created_at,
approved_at, voided_at…) were printed without conversion. `Company::localNow()`,
`Company::todayFor()`, `Controller::companyNow()/companyToday()` and
`Clock::local()` are the one way each is done now.*

*2026-09-28, push switched on for the first time (Firebase `kemp-805c6`) and
**no push had ever worked.** Four notifications — leave submitted, leave
decided, missing checkout, schedule updated — called `AppRoute` without
importing it, so every `toPush()` died with "class not found" inside the queue
worker, after the bell and the email had already gone. `ScheduleUpdated` also
named its method `toFcm()`, which `FcmChannel` never calls, so that one was
skipped without even failing. The push tests used a stand-in notification and
built none of the real ones. They now send all eight real classes through the
channel, and a scan fails any class that lists `'fcm'` without a `toPush()`.*

*`composer audit` is clean as of that date. Three advisories were open against
this project and are now closed: `maatwebsite/excel` 3.1.69 → 3.1.70 (high —
exports written outside the configured disk on a caller-controlled filename,
and this project was handing it one) and `league/commonmark` 2.8.3 → 2.10.1
(two medium). The unvalidated report window that fed that filename is fixed
in the application too — see the note under A7.14.*

**One mistake, four times, and worth naming so it is not made a fifth.** The
team board, attendance history, the team roster and now the leave calendar all
built a date from `DateTime.now()`. Attendance is judged in the company's
timezone and the phone is wherever its owner is, so for part of every day the
two disagree — and only one of the four endpoints (`/team/attendance`) refuses a
wrong date loudly. The others clamp it, or simply answer for the window they
were handed, so the screen shows the wrong days under a heading that reads
correctly. **The rule: the app never names a date the server has not named
first.** Ask for the default, read the day out of the reply, and count from
that. Every one of these tests passed against the broken code until the mock
server's clock was deliberately skewed from the test device's — a shared clock
makes the whole class of bug invisible.

*Verified 2026-09-12 and not re-run since: `flutter build appbundle` produces a
44.1 MB release bundle whose merged manifest reads minSdk 24 / targetSdk 36 and
whose compiled `ic_launcher.xml` still carries the `<monochrome>` layer. Stages
19 and 20 were found and done in that pass.*

*2026-09-17, from an audit for hardcoded values rather than from the roadmap:
**almost nothing was.** Company identity, timezone, currency, weekend days, the
eight attendance policies, overtime rules, leave types, report columns, the
app's API base URL, every app string and the app's tab list already came from
the database, config or the signed-in user. No TODOs, no dummy data, no static
chart arrays, and not one untranslated `Text()` in the app. Two things did not,
and both are now closed.

**Page sizes.** Twenty-four literals across twenty-one controllers, five
different values, no rule anybody could state for which list got which — drift,
not design. They live in `config/pagination.php` now, reached through
`perPage()` on the base `Controller` beside `companyId()`. The existing numbers
were kept rather than flattened: a dense audit table and an employee's own leave
list do not want the same count, and collapsing them would have been a visual
change made under cover of a refactor. A grep for `paginate([0-9]` found
twenty-three; the twenty-fourth was a `const PER_PAGE` and surfaced only because
`API-Reference_v1.md` documented a `per_page` for an endpoint the change had not
touched. The documentation caught what the search could not.

**`per_page` was advertised and never accepted.** `pageMeta()` has returned it
since the API was written, telling every client there was a page size worth
knowing about while no endpoint read one from the request. It is a real
parameter now, **clamped rather than validated** — a list that 422s because
somebody asked for one row too many fails a person reading their own leave in
order to protect a server that could have answered. Every endpoint's default is
the size it served before, so an app that sends nothing sees no change.

Nine tests, mutation-checked: dropping the `min()` fails the two clamp tests and
nothing else. **Verified on the live box after deploying `33fe439`**, which is
the part worth recording — `?per_page=5` answered 5, `?per_page=100000` answered
100 rather than an error, and no parameter answered 30, the pre-existing
default. Not left as a local test result.*

---

*Run on real hardware, 18 September 2026 — a Samsung SM A075F on Android 16,
over `adb reverse tcp:8000 tcp:8000` so the handset's own `localhost` reaches
the development machine down the USB cable. Worth recording because an emulator
cannot answer the question this did: the punch stored `device_emulator=0`
alongside `location_mocked=0` and `device_rooted=0`, and a real GPS fix from
the developer's handset. Those three flags are the anti-tamper checks, and on an emulator the
first of them reads `1`.

**No application code was changed.** Every defect found was in the seeded data,
and four things that looked like bugs were not:

- `/leave/approvals` returned `pending_count: 0` while a request sat at
  `status=pending`. Correct — it had `manager_approved_at` set and had moved to
  HR. The naive count is the one that was wrong.
- Team totals read 6 against 9 direct reports. Correct — three are `inactive`.
- A punch with no shift rostered came back `late`. Correct — `determineStatus()`
  falls back to 09:00–17:00 with 15 minutes' grace, and the punch was at 15:09.
- History showed 8h 10m with no break deducted. Correct, and the most
  interesting of the four: `actualBreakDeduction()` takes off only a break that
  was actually punched, because a nominal break deducted at five past nine would
  show somebody losing an hour to a lunch they have not had. `overtimeFor()`
  uses `settledBreakDeduction()` instead, which does apply the nominal one to a
  finished day. Two questions, two numbers, both right.

The full punch sequence — in, out, in, break_start, break_end, out — reported
21 minutes, which is the two stretches (18m and 4m) less the 1m 26s break. The
break arithmetic checks out on real data rather than in a fixture.

The seeded roster was stale: every `shift_assignment` was August, so Schedule
had nothing current. Regenerated for September across all eleven employees,
with the weekend read from `LeaveService::weekendDays()` rather than a hardcoded
Saturday and Sunday.

Still blocked, and confirmed on the handset rather than inferred: Firebase never
initialises (`Failed to load FirebaseOptions from resource`), so push is off.
The app logs it and carries on instead of crashing, which is the right
behaviour, but no notification will arrive until a Firebase project exists.*

---

*A5.7 gained a floor, 18 September 2026. The break policy had two settings and
needed three.

`break_is_minimum` exists so that a token break cannot buy back an hour: without
it, an employee who punches a one-minute lunch loses one minute while the
colleague who works straight through loses the full nominal sixty. That is
backwards, and it is a fifty-nine-minute-a-day hole that anybody would find
within a week. So the flag should be on.

Turning it on exposed the opposite error. The nominal break was charged to every
day of any length, so a twenty-three-minute day had an hour taken off it,
`worked` clamped at zero, and a day that was worked was reported as a day that
was not. Verified on real punches before the fix: 23 minutes present, 0 paid.

The missing idea is that **a break is a duty of the long day, not of every day**
— the usual statutory shape is "a break once the shift passes six hours". That
is now `attendance.break.nominal_after_minutes`, default 360, read through
`Shift::nominalBreakApplies()`. Below the floor only a break actually punched
comes off, and the deduction can never exceed the day itself.

**The floor had to be read on both sides, and that is the part worth
remembering.** `scheduled` and `worked` are subtracted from one another to get
overtime. Fixing only the worked side would have reported 240 worked against 180
scheduled for a four-hour shift worked exactly as rostered, and handed out an
hour of overtime every day — a worse bug than the one being fixed, and one that
pays out. `Shift::scheduledBreakDeduction()` exists so both sides read the floor
in the same place.

Fifteen tests, mutation-checked, and the mutations are the evidence: disabling
the floor everywhere kills seven, ignoring it on the scheduled side alone kills
exactly two — the two that hold scheduled and worked together — and removing the
never-exceed-the-day clamp kills exactly one. No test is redundant and none of
the three failures overlap. The first mutation run was thrown away: it wrote an
empty file, every test failed for that reason rather than the mutation, and a
mutation run where everything fails proves nothing.

Known gap: the app's break notice still reads "60 minutes comes off your hours,
even if you take less", which is now true only above the floor. The API does not
publish the floor, so the phone cannot say otherwise yet.*

---

*Lateness became a property of the day, 18 September 2026, and the history row
learned to admit when a day was not one continuous stretch.

A day is one row per punch, which is the right model and is not changing: it is
the only shape that survives a forgotten check-out, several stretches, a break
sitting inside one of them, and a regularisation inserting a punch into a day
already closed. What it does to arithmetic is that `status` is computed per
punch, so somebody who arrives at 09:34, steps out and comes back twice carries
three rows stamped `late` for one late morning.

Three places counted those rows. `ReportService` did it twice — the weekly
rollup and the per-employee stats — and `AttendanceService::monthlySummary` did
it once and then computed `ontime = presentDays - lateCount`, which for a single
day with three late punches is `max(0, 1 - 3)`: the clamp hid the subtraction
rather than preventing it. Present-days beside it had always counted days, so
two columns under headings promising the same question answered different ones.
On real punches: 28 present days, 7 late days, reported as 10.

`AttendanceService::lateDayKeys()` is now the single place that decides, keyed
`employeeId|date` so one collection can carry a company's week. **The first
punch of the day decides, not any of them** — arriving on time and returning
from an errand after the grace window is not a late arrival, however the second
row is stamped.

`total_ins` is gone. It counted punches, every percentage built on it divided a
day-based numerator by a punch-based denominator, and the department rollup
could have reported more than 100% on time. Removing it broke twenty-two tests
in one run, all from one undefined variable — worth recording because the first
look at that run was a tail that showed a single failure.

Eight tests, mutation-checked, and the mutations found two of them worthless
before they found anything else: the check-out test put its `out` *after* every
arrival, where it can never be picked as the first punch, and the out-of-order
test fetched rows with `orderBy('scanned_at')`, doing in the harness exactly the
sort the code under test exists to do. Both passed against the broken code. They
are fixed, and all four mutations now die to the test that claims them.

On the phone, `punches` had been in the payload and in the app's model all
along, parsed and dropped. A history row shows the **first** entry and the
**last** exit against a total that sums the stretches, so a day running 15:09 to
16:42 reads 22m and every part of that is true while the row looks like broken
arithmetic. It now says how many punches there were, and only above two, because
a "2 punches" on every ordinary line is noise that teaches people to stop
reading it.*
