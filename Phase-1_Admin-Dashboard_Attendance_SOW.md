# Phase 1 — Admin Web Dashboard (Attendance Focus)
### Scope of Work, Technical Plan & Delivery Report

**Project:** Employment Management Portal
**Prepared For:** Alfonzo
**Phase:** 1 of N — *Admin Web Dashboard*
**Primary Module:** Attendance Management
**Stack:** Laravel 12 (PHP) + MySQL + Blade / Bootstrap 5 (SmartHR template)
**Originally Issued:** 2026-07-16
**Revised:** 2026-07-29 *(supersedes all earlier versions)*

---

## ✅ BUILD STATUS — Phase 1 Delivered

Phase 1 is **built, running and verified** in the `hrms/` folder.

| Item | Status |
|---|---|
| Authentication (Admin + HR login) | ✅ Delivered |
| Company / Offices / Departments / Designations | ✅ Delivered |
| Employee management + bulk import | ✅ Delivered |
| **Attendance — one-tap Check In / Check Out** | ✅ Delivered |
| Shift & schedule (base) | ✅ Delivered |
| Reports + PDF / Excel export | ✅ Delivered |
| Dashboard with live tiles & charts | ✅ Delivered |
| Roles & permissions editor | ✅ Delivered |

**Scale delivered:** 9 database models · 14 controllers · 15 migrations · 28 screens · 16 permissions.
All pages return HTTP 200 and were verified end-to-end against seeded demo data.

**How to run:** start MySQL (XAMPP Control Panel) → `cd emp && php artisan serve` →
open `http://127.0.0.1:8000` → log in as `admin@emp.test` / `password`
(HR demo account: `hr@emp.test` / `password`).

> ### ⚠️ Two scope changes since the original document
> **1. QR attendance was removed entirely** at your request on **2026-07-20** and replaced with a
> **one-tap Check In / Check Out button**. The rotating-QR engine, the office kiosk screen and the
> PWA scanner have all been deleted from the codebase. No QR hardware, tablet or display is needed.
>
> **2. HR login is now enabled.** The original plan restricted Phase 1 to Admin-only login.
> Admin **and** HR can now sign in, with HR blocked from Company, Offices, Roles and Settings.

---

## 1. Purpose of This Phase

This document defines **Phase 1** of the larger Employment Management Portal project. Rather than building the
Android/iOS apps first, Phase 1 delivers a **web-based Admin Dashboard** with the **Attendance
module** as the core focus.

This order front-loads the parts every later phase depends on:

- A **database schema** for companies, employees, shifts and attendance.
- A **Laravel backend** the future mobile apps will reuse through an API layer.
- A working **admin panel** to configure the system and view/verify attendance data.

---

## 2. Phase 1 Goals — all met

1. ✅ Secure login with role-based access (Admin / HR).
2. ✅ Company, office, department and designation setup.
3. ✅ Employee management (CRUD + bulk import).
4. ✅ **Attendance module** — button-based clock in/out, logs, history, scoring, summaries.
5. ✅ Attendance reporting with PDF + Excel export.
6. ✅ A dashboard that visualises attendance data in real time.

**Deliberately out of Phase 1:** Android app, iOS app, mobile API, AI Assistant, push notifications,
leave management, payroll.

### ✅ Confirmed Client Decisions

| Date | Decision |
|---|---|
| 2026-07-16 | Dashboard to be built on the **client-supplied frontend template** (SmartHR Bootstrap 5). |
| 2026-07-17 | RBAC via **Spatie `laravel-permission`** — roles `admin`, `hr`, `employee`. |
| **2026-07-20** | **Attendance = one-tap button check in/out. QR dropped completely.** |
| **2026-07-20** | Employees punch from **their own phone or PC, from any location** — no hardware. |
| **2026-07-20** | **GPS + IP are recorded but never block a punch**, so WFH and remote staff can clock in. |
| 2026-07-20 | Server time is authoritative — the device clock is never trusted. |
| 2026-07-27 | **Admin + HR** may log in to the dashboard; the Employee role gets a locked-down self-service portal only. |

---

## 3. Architecture Overview

```
┌──────────────────────────────┐   ┌──────────────────────────────┐
│   Admin / HR Web Dashboard   │   │   Employee Self-Service      │
│   (Blade + SmartHR BS5)      │   │   Portal — Check In/Out      │
└──────────────┬───────────────┘   └──────────────┬───────────────┘
               │      HTTP (session web routes)   │
┌──────────────▼──────────────────────────────────▼───────────────┐
│                     Laravel 12 Application                       │
│      Controllers · Services · Eloquent Models · Spatie RBAC      │
└──────────────────────────────┬───────────────────────────────────┘
                               │
┌──────────────────────────────▼───────────────────────────────────┐
│                            MySQL DB                               │
│  companies · offices · departments · designations · employees ·   │
│  shifts · attendance_logs · attendance_scores · users · roles     │
└───────────────────────────────────────────────────────────────────┘
                               ▲
                               │  REST API (Sanctum) — NOT YET BUILT
                   ┌───────────┴────────────┐
                   │  Future: Android / iOS │   ← later phase
                   └────────────────────────┘
```

**Key point for planning:** the mobile apps will hit this **same Laravel backend**. Phase 1 built the
web (session) side. **The API layer does not exist yet** — `routes/api.php` and Laravel Sanctum are
still to be added, and they are a hard prerequisite for any mobile work.

---

## 4. Attendance Module — What Was Built

The heart of Phase 1, delivered as a button-based system.

| Feature | Implementation |
|---|---|
| **One-tap Check In / Out** | Employee logs into the web portal on their own phone or PC and taps a single button. The system auto-detects whether this is an in or an out punch from the last record. |
| **Server-authoritative time** | Every timestamp is written server-side. The device clock is never read or trusted. |
| **Location capture** | Browser GPS (`navigator.geolocation`) plus IP address are saved on each punch **as a record only — a punch is never rejected for location.** |
| **Work mode per employee** | Each employee is set to `office`, `wfh`, or `hybrid`, which drives per-person policy. |
| **Duplicate-punch cooldown** | Rapid repeat taps are ignored to prevent accidental double records. |
| **Late / early-leave status** | Each punch is scored against the shift assigned to the employee's department, including a configurable grace period. |
| **Attendance logs** | Full event table: employee, office, type, timestamp, work date, status, source, latitude, longitude, IP. |
| **Attendance history** | Filterable, paginated history view with office and department filters. |
| **Attendance scoring** | Monthly per-employee score: present days, late count, absences, early leaves, on-time %. |
| **Daily summary** | Present / late / absent / headcount tiles on the dashboard. |

### 4.1 Why the button model was chosen
- **No hardware cost** — no tablet, kiosk, scanner or biometric device to buy or maintain.
- **Works for remote and WFH staff** — anyone can punch from anywhere, which a fixed office QR cannot support.
- **Nothing to install** — it runs in any browser; no app download required for Phase 1.
- **Zero rework for mobile** — the mobile app will call the same check-in logic through the API.

### 4.2 Geofencing — built but switched off
Each office record already stores `latitude`, `longitude` and a `geofence_radius` (default 100 m).
**Enforcement is deliberately disabled** in line with the 2026-07-20 decision that location must never
block a punch. If you later want office-only clock-in for specific staff, this can be switched on
without a schema change.

---

## 5. Supporting Modules — What Was Built

| Module | Delivered Features |
|---|---|
| **Auth & Roles** | Login, logout, change password, profile page, Spatie RBAC with 16 permissions, roles & permissions editor UI. Admin sees everything; HR is blocked from Company, Offices, Shifts, Roles and Settings. |
| **Company** | Company profile — name, logo, address, timezone. |
| **Offices** | Branch CRUD with address, city, GPS coordinates and geofence radius. |
| **Departments** | CRUD, employee assignment, shift assignment, department filtering in reports. |
| **Designations** | Job-title CRUD and assignment. |
| **Employees** | CRUD, deactivate, assign office/department/designation, work mode, employment details, login credential creation, **CSV/Excel bulk import**. |
| **Shifts** | Shift CRUD (start, end, break minutes, grace period, colour), shift-per-department assignment, weekly roster view. |
| **Reporting** | Attendance report with date/office/department filters, late report, outlier report, department report, **PDF + Excel export including Location and IP columns**. |
| **Dashboard** | Live tiles (present, late, absent, headcount), recent attendance feed, charts. |

---

## 6. Database Schema — As Built

```
users             — id, name, email, password, company_id  (+ Spatie roles/permissions tables)
companies         — id, name, logo, address, timezone, settings
offices           — id, company_id, name, code, address, city,
                    latitude, longitude, geofence_radius, is_active
departments       — id, company_id, name, shift_id
designations      — id, company_id, name
employees         — id, company_id, office_id, department_id, designation_id, user_id,
                    employee_code, first_name, last_name, email, phone, avatar,
                    date_of_birth, gender, hire_date, work_mode, status
shifts            — id, company_id, name, code, start_time, end_time,
                    break_minutes, late_grace_minutes, colour, is_active
attendance_logs   — id, employee_id, office_id, type (in/out), scanned_at, work_date,
                    status (ontime/late/early_leave/invalid), source,
                    latitude, longitude, ip_address, notes
attendance_scores — id, employee_id, period, period_type, present_days, late_count,
                    absent_count, early_leave_count, ontime_pct, score
```

*Not yet created (later phases): `leave_requests`, `leave_types`, `leave_balances`,
`notifications`, `holidays`, `audit_logs`, `personal_access_tokens` (Sanctum).*

> **Note:** the `offices.qr_secret` column and the `configure-qr` permission are harmless leftovers
> from the removed QR feature. They are unused and can be dropped in a cleanup migration.

---

## 7. Deliverables — Phase 1 Checklist

| # | Deliverable | Status |
|---|---|---|
| 1 | Laravel 12 project configured for XAMPP (Apache + MySQL) | ✅ |
| 2 | MySQL migrations + seeders for the full schema | ✅ |
| 3 | Admin/HR dashboard UI on the SmartHR template | ✅ |
| 4 | Employee self-service check in/out portal | ✅ |
| 5 | Attendance engine — punch, validation, logging, scoring, summaries | ✅ |
| 6 | Shift engine + weekly roster | ✅ |
| 7 | CSV / Excel employee import | ✅ |
| 8 | PDF + Excel report export | ✅ |
| 9 | Roles & permissions system + editor | ✅ |
| 10 | Setup / README documentation | ✅ |
| 11 | Custom logo + favicon applied throughout | ✅ |

---

## 8. Build Milestones — All Completed

1. ✅ **M1 — Foundation:** Laravel on XAMPP, DB config, auth, SmartHR base layout.
2. ✅ **M2 — Company & Employees:** company/office/department/designation setup, employee CRUD + import.
3. ✅ **M3 — Attendance Core:** schema, check in/out flow, validation, logging.
4. ✅ **M4 — Dashboard & History:** live tiles, attendance history and filtering.
5. ✅ **M5 — Scoring & Reports:** scoring logic, summaries, PDF/Excel export.
6. ✅ **M6 — Polish & Handoff:** demo data, branding, documentation.

---

## 9. Known Limitations of Phase 1

Stated plainly so there are no surprises at handover:

| Limitation | Detail |
|---|---|
| **Running on demo data** | The system is populated by `DemoDataSeeder` / `AttendanceSeeder` — placeholder offices, departments and employees. **Real company data is required before go-live.** |
| **No mobile API** | `routes/api.php` and Laravel Sanctum are not yet installed. No mobile app can be started until they are. |
| **No leave management** | The `manage-leave` permission is seeded, but no leave tables, logic or screens exist. |
| **No notifications** | No email, in-app or push notifications of any kind. |
| **No automated test suite** | Verification was manual. Only the two default Laravel stub test files exist. |
| **No manual attendance correction** | HR cannot yet edit or add a punch after the fact. |
| **No auto-absent job** | Missed days are not automatically marked; there is no task scheduler running. |
| **Not deployed** | The system runs locally on XAMPP. Hosting, domain, HTTPS and production hardening are not done. |

---

## 10. What I Need From You

To close out Phase 1 and correctly scope what follows:

### 🔴 Data required before go-live
1. **Real office list** with addresses and GPS coordinates for each branch.
2. **Real department list** and **job titles**.
3. **Shift definitions** — start time, end time, break, and how many minutes late is "late".
4. **Employee data** in the supplied bulk-import format.
5. **Hosting and technical access** — server, domain, database and email/SMTP credentials.
6. **Company branding** — legal name, logo file, brand colours.

### 🔴 Legal / HR sign-off
7. An **employee privacy notice** covering GPS and IP capture at check-in.
8. A **data retention policy** — how long attendance records are kept.

### ❓ Two decisions that determine the whole roadmap
9. **Do you want Leave Management?** *(request, approval workflow, balances, calendar)*
10. **Do you want the mobile app — and if so, Android only, or Android + iOS?**

*A separate document — `Client-Requirements_Information-Needed.html`, with the one-page
`Client-Checklist_One-Page.html` — sets all of this out in fill-in form.*

---

## 11. Recommended Next Phases

| Phase | Scope | Why this order |
|---|---|---|
| **Phase 2** | **Sanctum API layer** (`routes/api.php`, token auth, attendance + profile endpoints) | Hard blocker — every mobile feature depends on it |
| **Phase 3** | **Leave Management** (types, requests, approvals, balances, calendar) | The largest missing HR module; must exist on the web before the app can expose it |
| **Phase 4** | **Notifications + task scheduler** (email, in-app, auto-absent, reminders) | Powers approvals, reminders and automated processing |
| **Phase 5** | **Finish Shift & Schedule** (per-employee override, rotating shifts, roster planner, swaps) | The roster must be complete before staff see it on a phone |
| **Phase 6** | **Mobile App v1** — login, punch, self-service, offline punch queue | First app release |
| **Phase 7** | **Mobile App v2** — leave in-app, push notifications, manager mode | Second app release |
| **Phase 8** | **Attendance depth** — manual correction, regularisation, overtime, breaks, payroll-ready export | Requires mature data from the phases above |
| **Phase 9** | **AI HR Assistant** — natural-language queries over attendance and leave | Needs a full dataset to be useful |

*The complete feature-by-feature breakdown — 40 built, 112 planned — is in
`Feature-List_Web-and-App.md`.*

---

## 12. Recommendations

- **Enable HTTPS before go-live.** Browser GPS capture is blocked on insecure origins, so check-in
  location will silently stop being recorded over plain HTTP.
- **Add the API layer next, even if the mobile app is deferred.** It costs little now and prevents a
  larger refactor later.
- **Add an audit trail** before HR gains the ability to edit attendance records — an editable
  attendance log without history is a payroll dispute waiting to happen.
- **Keep geofencing off** unless a specific site genuinely requires on-premises-only clock-in. It
  generates far more support complaints than it prevents time fraud.
- **Set up database backups** before real employee data is loaded.

---

*End of Phase 1 Scope & Delivery Report — revised 2026-07-29.
Phase 1 is complete and awaiting your data and the two roadmap decisions in §10.*
