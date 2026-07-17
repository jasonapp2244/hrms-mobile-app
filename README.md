# HR & Attendance Management — Admin Web Dashboard (Phase 1)

Laravel 12 + MySQL admin dashboard focused on the **Attendance module** (QR-based clock in/out).
Built on the SmartHR (Bootstrap 5) template. Admin-only login in Phase 1; HR/Employee roles are
structured (Spatie RBAC) and can be enabled later without schema changes.

## Requirements
- PHP 8.2+ (XAMPP)
- MySQL / MariaDB (XAMPP)
- Composer

## Setup (already done during build)
```bash
composer install
# .env is already configured for MySQL db "hrms"
php artisan key:generate
php artisan migrate --seed
```

## Run the app
1. Start **MySQL** (XAMPP Control Panel → MySQL → Start).
2. From the `hrms/` folder:
   ```bash
   php artisan serve
   ```
3. Open **http://127.0.0.1:8000**

### Demo login (Phase 1 = admin only)
- **Email:** admin@hrms.test
- **Password:** password

*(An `hr@hrms.test` user exists with the HR role but cannot log in yet — Phase 1 gate.)*

## Attendance / QR flow
- **QR Kiosk** (`Attendance → QR Kiosk`): a screen for each office that displays a **dynamic
  rotating QR** (rotates every 20s). Put it on a tablet/monitor at the entrance.
- **PWA Scanner** (`Attendance → QR Scanner`): opens the device camera in the browser (no app
  install). Employee selects their name and scans the kiosk QR to clock in/out.
- The server validates the rotating token (HMAC of the office secret + time window), rejects
  expired/forged codes, and records a **server-side timestamp** (device clock is never trusted).
- Clock in/out is auto-detected; lateness is flagged against each office's `work_start_time` + grace.

## Key structure
```
app/Services/QrTokenService.php     # rotating HMAC QR token engine
app/Services/AttendanceService.php  # clock in/out, late detection, scoring
app/Http/Controllers/AttendanceController.php
app/Models/                         # Company, Office, Department, Designation, Employee, AttendanceLog, AttendanceScore
database/seeders/                   # RolePermissionSeeder, DemoDataSeeder
resources/views/                    # Blade views (SmartHR template)
public/assets/                      # SmartHR template assets
```

## Roles & Permissions (Spatie)
- **admin** — all permissions (only role that can log in now)
- **hr** — HR subset (seeded, login disabled in Phase 1)
- **employee** — reserved for the mobile self-service phase

Manage under **Administration → Roles & Permissions**.

## Future phases (not built — reserved in the data model)
Leave management · Shift/Schedule engine · AI HR Assistant · Notifications · Android/iOS apps
(served by the same Laravel API via Sanctum).
