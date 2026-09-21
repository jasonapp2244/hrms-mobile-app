# Full Professional Employment Management Portal — Additional Modules

**Project:** Employment Management Portal
**Date:** 2026-07-29
**Scope:** Modules required for a **complete HRMS** that are **not** covered in `Feature-List_Web-and-App.md` or `Professional-HRMS_Recommended-Features.html`.

Those documents cover the *attendance product* — the current build plus Leave, Notifications, API, mobile app and the P0 fixes. This document covers the **rest of a full HR platform**: the employee lifecycle, money, workplace operations, and the attendance-technology extensions.

**Fit rating:** ⭐⭐⭐ natural extension of what exists · ⭐⭐ standard HRMS expectation · ⭐ breadth / enterprise

---

# TIER A — Attendance Technology Extensions
*Highest fit — these build directly on `attendance_logs`, `offices` and `employees` as they exist today.*

## A1. Face Recognition / Selfie Punch ⭐⭐⭐
Attendance products live or die on proof-of-presence. GPS and IP are already captured; a face check closes the loop on buddy-punching.

- Selfie captured at every check in/out, stored against the punch
- Face enrolment per employee (uses the unused `employees.avatar` column)
- Automatic face match with confidence score; low-confidence punches flagged for HR review
- Liveness detection (blink / head-turn) to defeat photo spoofing
- Photo audit gallery — HR reviews any punch visually
- Configurable per company: off / photo-only / face-match-required

**New tables:** `employee_face_profiles`, plus `photo_path` + `face_match_score` on `attendance_logs`
**Effort:** 8–12 d (10–15 d with a third-party face API)

## A2. Biometric Device Integration ⭐⭐⭐
Many organisations already own fingerprint terminals and will not replace them.

- Integration with ZKTeco / eSSL / Suprema style devices
- Device registry per office, push or pull sync
- Automatic import of device punches into `attendance_logs` (`source = 'device'`)
- Device health monitoring and last-sync alerts
- Fingerprint / card / face enrolment mapped to `employee_code`

**New tables:** `biometric_devices`, `device_sync_logs`
**Effort:** 10–15 d

## A3. Shared Kiosk Mode ⭐⭐⭐
A tablet at the entrance for staff who have no phone or company login — this replaces the QR kiosk that was removed, without the QR weaknesses.

- Tablet screen at the office entrance, permanently logged in to the office
- Employee enters their code + PIN, or taps their photo from a grid
- Optional selfie confirmation (ties to A1)
- Fully offline-capable, syncing when the connection returns
- Locked-down browser mode — no navigation away from the punch screen

**New tables:** `kiosk_devices`, plus `pin_hash` on `employees`
**Effort:** 5–7 d

## A4. Trusted Network / IP Whitelist ⭐⭐⭐
The system already records `ip_address` on every punch but never uses it. Turning it into a verification rule is close to free.

- Whitelist office IP ranges per office
- Punch tagged as on-network / off-network automatically
- Policy per employee work mode: office staff must be on-network, WFH staff exempt
- Off-network punch reports for HR
- Optional hard block for high-security clients

**New tables:** `office_ip_ranges`, plus `network_verified` flag on `attendance_logs`
**Effort:** 2–3 d ⭐ *best value-to-effort ratio in this document*

## A5. Field Force / Field Staff Attendance ⭐⭐⭐
Sales, delivery and service teams never sit in an office. This is a large, underserved market segment for an attendance product.

- Check in at client sites, not offices
- Client / site registry with GPS coordinates
- Visit log — arrival, departure, duration, notes, photo
- Route replay for the day on a map
- Distance travelled and visit-count reports
- Battery-aware periodic location tracking (with clear consent)

**New tables:** `client_sites`, `field_visits`, `location_pings`
**Effort:** 12–15 d (needs the mobile app first)

## A6. Timesheets & Project Time ⭐⭐⭐
Turns raw attendance hours into billable, chargeable data — essential for agencies, consultancies and IT services.

- Project and task registry with clients
- Daily timesheet: split the day's attendance hours across projects
- Billable vs non-billable classification
- Timesheet approval by project manager
- Utilisation reports per employee, project profitability
- Reconciliation against attendance — flags hours logged but not present

**New tables:** `projects`, `tasks`, `timesheets`, `timesheet_entries`
**Effort:** 12–15 d

## A7. Travel & On-Duty Attendance ⭐⭐⭐
Currently a staff member on a business trip is simply absent. This is a daily source of incorrect reports.

- Travel / on-duty request with dates, destination and purpose
- Manager approval
- Approved days marked "on duty" — counted as present, not absent
- Travel expense link (see C2)
- Travel calendar and history

**New tables:** `travel_requests`, plus an `on_duty` day-status
**Effort:** 4–5 d

## A8. Contract, Hourly & Daily-Wage Workers ⭐⭐
Manufacturing, retail, hospitality and construction clients cannot use a salaried-only model.

- Employment type per employee: permanent / contract / hourly / daily-wage
- Hourly and daily rate on the employee record
- Wage calculation directly from attendance hours
- Piece-rate / output-based option
- Contractor / labour-supplier registry with per-contractor attendance reports
- Contract expiry alerts

**New tables:** `contractors`, plus `employment_type`, `hourly_rate`, `daily_rate` on `employees`
**Effort:** 6–8 d

## A9. Shift Marketplace & Swaps ⭐⭐
Extends the existing Shift module rather than replacing it.

- Open shifts published for staff to claim
- Shift swap requests between employees, manager-approved
- Availability preferences submitted by staff
- Understaffing alerts on the roster
- Auto-suggested roster fills based on availability and hours worked

**New tables:** `open_shifts`, `shift_swap_requests`, `employee_availability`
**Effort:** 8–10 d

---

# TIER B — Employee Lifecycle
*Standard HRMS expectation. This is what makes the product "HR" rather than "attendance".*

## B1. Recruitment / Applicant Tracking (ATS) ⭐⭐
The lifecycle stage before an employee exists — currently the record simply appears from nowhere.

- Job requisition and approval
- Public careers page with job listings
- Application capture with CV upload
- Candidate pipeline (applied → screened → interviewed → offered → hired)
- Interview scheduling with panel and feedback forms
- Offer letter generation and e-acceptance
- **One-click convert hired candidate → employee record** (no re-typing)
- Source-of-hire and time-to-hire analytics

**New tables:** `job_openings`, `candidates`, `applications`, `interviews`, `offers`
**Effort:** 15–20 d

## B2. Probation & Confirmation ⭐⭐⭐
Small module, immediate value — `hire_date` already exists, so the whole thing is derivable.

- Probation period per employee, auto-calculated from hire date
- Automatic reminders to the manager before probation ends
- Confirmation review form and decision (confirm / extend / terminate)
- Probation status visible on the employee record and dashboard
- Attendance during probation surfaced in the review

**New tables:** `probation_reviews`, plus `probation_end_date`, `confirmation_status` on `employees`
**Effort:** 3–4 d

## B3. Performance Management ⭐⭐
- Goal / KPI / OKR setting per employee, cascaded from department goals
- Appraisal cycles (annual, half-yearly, quarterly)
- Self-assessment + manager review + optional 360° peer feedback
- Rating scales and weighted scorecards
- **Attendance score feeds the appraisal automatically** — the data already exists
- Performance improvement plans (PIP)
- Appraisal history and rating distribution reports
- Increment / promotion recommendation flowing to payroll

**New tables:** `goals`, `appraisal_cycles`, `appraisals`, `feedback_responses`
**Effort:** 15–20 d

## B4. Training & Development ⭐⭐
- Course catalogue (internal and external)
- Training session scheduling with trainer and venue
- Employee enrolment and **training attendance** (reuses the attendance engine)
- Certification tracking with expiry alerts — critical for safety and compliance roles
- Skill matrix: skills held per employee vs skills required per designation
- Training cost tracking and effectiveness feedback

**New tables:** `courses`, `training_sessions`, `enrolments`, `certifications`, `skills`, `employee_skills`
**Effort:** 12–15 d

## B5. Exit & Offboarding ⭐⭐⭐
The `status = 'terminated'` enum exists but there is no process behind it.

- Resignation submission and acceptance
- Notice period calculation and last-working-day tracking
- Clearance checklist across departments (IT, Finance, Admin, HR)
- **Asset return verification** (links to D3)
- Final settlement calculation — pending leave, salary, deductions
- Exit interview form and attrition-reason analytics
- Automatic account deactivation on the last working day
- Experience / relieving letter generation

**New tables:** `resignations`, `clearance_items`, `exit_interviews`
**Effort:** 8–10 d

## B6. Disciplinary & Grievance ⭐
- Incident logging with evidence attachments
- Warning letters (verbal, written, final) with acknowledgement
- **Automatic trigger from attendance** — e.g. late 5 times in a month raises a flag
- Grievance intake with confidential handling
- Case status tracking and resolution record
- Full disciplinary history on the employee record

**New tables:** `disciplinary_cases`, `warnings`, `grievances`
**Effort:** 6–8 d

---

# TIER C — Payroll & Money
*The earlier documents proposed only a payroll **export**. This is the actual payroll engine.*

## C1. Payroll Engine ⭐⭐
- Salary structure per employee — basic, allowances, deductions
- Salary revision history with effective dates
- Pay period processing (monthly / bi-weekly / weekly)
- **Attendance-driven calculation** — absences, half-days, overtime, leave without pay all flow in automatically
- Statutory deductions (tax, provident fund, insurance) — configurable per country
- Payslip generation (PDF) with employee self-service download
- Bank transfer file export
- Payroll register, cost-per-department and cost-per-office reports
- Payroll lock and approval workflow before disbursement
- Arrears and off-cycle payments

**New tables:** `salary_structures`, `salary_components`, `payroll_runs`, `payslips`, `payslip_lines`
**Effort:** 25–30 d · *the single largest module in this document*

## C2. Expense & Reimbursement ⭐⭐
- Expense claim submission with receipt photograph
- Expense categories with per-category limits
- Multi-level approval (manager → finance)
- Mileage claims — can draw distance from field-visit data (A5)
- Reimbursement status tracking
- **Flows into payroll** as a payable line
- Expense reports per department, project and period

**New tables:** `expense_categories`, `expense_claims`, `claim_items`
**Effort:** 8–10 d

## C3. Loans & Salary Advances ⭐
- Loan / advance request with approval
- Repayment schedule generation
- Automatic instalment deduction in payroll
- Outstanding balance visible to employee and finance

**New tables:** `loans`, `loan_repayments`
**Effort:** 5–6 d

## C4. Benefits & Insurance ⭐
- Benefit plan catalogue (medical, life, retirement)
- Employee enrolment with dependants
- Coverage period and renewal alerts
- Employer vs employee contribution split
- Claim reference tracking

**New tables:** `benefit_plans`, `benefit_enrolments`, `dependants`
**Effort:** 8–10 d

## C5. Statutory Compliance & Reporting ⭐
- Country-specific labour reports and filing formats
- Minimum wage and maximum working-hours compliance checks
- Overtime-limit breach alerts (a legal exposure in most jurisdictions)
- Mandatory register generation for labour inspection
- Configurable per country so the product can be sold across regions

**New tables:** `compliance_rules`, `statutory_reports`
**Effort:** 10–15 d per country

---

# TIER D — Workplace Operations

## D1. Announcements & Noticeboard ⭐⭐⭐
Cheap, highly visible, and it makes the platform somewhere staff actually visit.

- Company-wide, per-office or per-department announcements
- Rich content with attachments
- Pin important notices; schedule publish and expiry
- Read receipts — HR sees who has actually seen a policy
- Push to mobile app
- Birthday and work-anniversary auto-announcements (`date_of_birth` and `hire_date` already exist)

**New tables:** `announcements`, `announcement_reads`
**Effort:** 4–5 d

## D2. HR Helpdesk / Ticketing ⭐⭐
- Employee raises an HR query (payroll, leave, document request)
- Category routing to the right HR member
- SLA tracking and escalation
- Conversation thread with attachments
- Knowledge base of HR policies and FAQs
- Ticket volume analytics — shows HR where the friction actually is

**New tables:** `tickets`, `ticket_replies`, `ticket_categories`, `kb_articles`
**Effort:** 8–10 d

## D3. Asset Management ⭐⭐
- Asset registry (laptops, phones, vehicles, ID cards, uniforms)
- Issue to employee with acknowledgement and condition note
- Return on exit — **feeds the offboarding clearance checklist (B5)**
- Maintenance and warranty tracking
- Asset value and depreciation reporting
- Unreturned-asset alerts

**New tables:** `assets`, `asset_categories`, `asset_assignments`
**Effort:** 6–8 d

## D4. Employee Engagement ⭐
- Surveys and pulse polls with anonymous responses
- Recognition / kudos wall — peer-to-peer appreciation
- Reward points and redemption
- Engagement score trend per department
- Suggestion box

**New tables:** `surveys`, `survey_responses`, `recognitions`
**Effort:** 8–10 d

## D5. Visitor & Contractor Management ⭐
- Visitor pre-registration and host notification
- Check in / out at reception, badge printing
- Contractor and temporary-worker attendance using the same engine
- Site-safety induction acknowledgement
- Evacuation list — everyone currently on site

**New tables:** `visitors`, `visitor_logs`
**Effort:** 6–8 d

## D6. Employee ID Cards & Badges ⭐
- ID card designer with company branding
- Bulk generation with photo, code and barcode/QR
- Print-ready PDF output
- Expiry and reissue tracking

**Effort:** 4–5 d

---

# TIER E — Platform & Integration

## E1. Single Sign-On ⭐⭐
- Google Workspace and Microsoft 365 login
- SAML 2.0 for enterprise clients
- Automatic user provisioning and de-provisioning from the directory
- Enforced SSO-only mode per company

**Effort:** 6–8 d · *frequently a hard purchase requirement for larger clients*

## E2. Chat Platform Integration ⭐⭐
Meets staff where they already are, and dramatically increases punch compliance.

- Check in / out via Slack, Microsoft Teams or WhatsApp
- Leave requests and approvals in chat
- Daily attendance digest posted to a channel
- Absence and late alerts to a manager's DM

**Effort:** 8–10 d

## E3. Calendar Sync ⭐
- Approved leave pushed to Google / Outlook calendar
- Shift roster subscribed as a calendar feed
- Holidays and company events synced
- Interview scheduling into panel calendars

**Effort:** 4–5 d

## E4. Webhooks & Public API ⭐
- Outbound webhooks on key events (punch, leave approved, employee created)
- Public REST API with per-client keys and scopes
- Zapier / Make connector
- Import/export centre for bulk operations

**Effort:** 6–8 d

## E5. Localisation ⭐⭐
Required to sell outside a single language market.

- Multi-language interface (web + app)
- RTL layout support (Arabic, Urdu, Hebrew)
- Per-user language preference
- Locale-aware dates, numbers and currency
- Multi-currency for payroll

**Effort:** 8–10 d

---

# Summary

| Tier | Modules | Fit | Indicative effort |
|---|---|---|---|
| **A — Attendance extensions** | 9 | ⭐⭐⭐ Highest | ~70 d |
| **B — Employee lifecycle** | 6 | ⭐⭐ Standard | ~65 d |
| **C — Payroll & money** | 5 | ⭐⭐ Standard | ~65 d |
| **D — Workplace operations** | 6 | ⭐ Breadth | ~40 d |
| **E — Platform & integration** | 5 | ⭐⭐ Enterprise | ~35 d |
| **Total** | **31 new modules** | | **~275 days** |

## Recommended first six

Chosen for the ratio of value to effort, and because each one extends data the system already holds:

| # | Module | Effort | Why first |
|---|---|---|---|
| 1 | **A4 — Trusted network / IP whitelist** | 2–3 d | The IP is already recorded on every punch; this turns dead data into a verification feature |
| 2 | **B2 — Probation & confirmation** | 3–4 d | Fully derivable from `hire_date`; genuine HR value for a few days' work |
| 3 | **D1 — Announcements & noticeboard** | 4–5 d | Makes the platform a destination, not just a punch clock |
| 4 | **A7 — Travel / on-duty** | 4–5 d | Fixes a live reporting error — travelling staff currently show as absent |
| 5 | **A3 — Kiosk mode** | 5–7 d | Reaches staff with no smartphone; restores the entrance-terminal use case |
| 6 | **A1 — Face / selfie punch** | 8–12 d | The strongest competitive differentiator for an attendance product |

**Total: ~30 days for six modules** that visibly widen the product without touching the payroll or lifecycle work.

## Positioning note

Not everything here should be built. The realistic strategic choice is:

- **Deep attendance specialist** — Tier A in full, plus payroll export. Sells against general HR suites on accuracy, proof-of-presence and field-staff coverage.
- **Full HR suite** — Tiers A–E. A much larger investment (~275 days) competing directly with established platforms.

Tier A is where this product is already strongest and where the remaining work is cheapest.

---

*Companion documents: `Requirements.html` · `Feature-List_Web-and-App.md` · `Professional-HRMS_Recommended-Features.html`*
