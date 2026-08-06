<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftSwapAdminController;
use App\Http\Controllers\ShiftSwapController;
use Illuminate\Support\Facades\Route;

// ---- Guest / Auth ----
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login']);

    // Password reset. The broker enforces its own 60-second gap between links
    // for one address; these limiters are the other half — they stop one client
    // walking a list of addresses, which the per-address throttle does nothing
    // about. Names are Laravel's defaults because Password::reset and the mail
    // template both resolve them.
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:6,1')->name('password.update');
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->homeRoute());
    }
    return redirect()->route('login');
});

// ---- Public legal pages ----
// No auth, deliberately. Google Play requires a privacy policy and a data
// deletion route reachable without an account — a reviewer has no login, and
// neither does somebody who has left the company and wants their record removed.
// The company supplies its own name and HR address so the pages read as the
// employer's rather than the software's — it is the employer who answers a
// deletion request, not us. Null-safe throughout: a fresh install with no
// company set up must still serve both pages, because a Play reviewer may
// reach them before anyone has finished configuring anything.
Route::get('privacy', fn () => view('legal.privacy', [
    'company' => \App\Models\Company::query()->orderBy('id')->first(),
]))->name('legal.privacy');

Route::get('account-deletion', fn () => view('legal.deletion', [
    'company' => \App\Models\Company::query()->orderBy('id')->first(),
]))->name('legal.deletion');

// ---- Notifications (everyone who can sign in) ----
// Outside both the staff and employee groups on purpose: an approval alert
// reaches a manager in the portal and an HR user in the dashboard, and they
// should not need two different screens to read the same message. Every query
// is scoped to the signed-in user by the relation itself.
Route::middleware('auth')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{id}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
});

// ---- Employee self-service portal (employee + manager roles) ----
// A locked-down area: employees can check in/out and view their own attendance,
// and nothing else in the application. Managers hold this role too — their
// approval screens live here rather than in the admin app, and each one is
// additionally gated on approve-leave / view-team and scoped to their own team.
Route::middleware(['auth', 'role:employee|manager'])->prefix('employee')->name('employee.')->group(function () {
    Route::get('dashboard', [EmployeePortalController::class, 'dashboard'])->name('dashboard');
    // One-tap button check in/out (works on mobile or PC, any location).
    Route::post('check', [EmployeePortalController::class, 'check'])->name('check');

    // Own leave: balances, apply, withdraw. Every action is scoped to the
    // signed-in employee inside the controller, so no permission is needed —
    // holding the employee role is the authorisation.
    Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveRequestController::class, 'store'])->name('leave.store');
    Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');

    // Shift swaps. Requesting, accepting and withdrawing are open to any
    // employee — each action is scoped to the record in the controller, since
    // being named on a swap is what authorises acting on it.
    Route::prefix('swaps')->name('swaps.')->group(function () {
        Route::get('/', [ShiftSwapController::class, 'index'])->name('index');
        Route::post('/', [ShiftSwapController::class, 'store'])->name('store');
        Route::post('{swap}/accept', [ShiftSwapController::class, 'accept'])->name('accept');
        Route::post('{swap}/decline', [ShiftSwapController::class, 'decline'])->name('decline');
        Route::post('{swap}/cancel', [ShiftSwapController::class, 'cancel'])->name('cancel');
        Route::post('{swap}/approve', [ShiftSwapController::class, 'approve'])->name('approve');
        Route::post('{swap}/reject', [ShiftSwapController::class, 'reject'])->name('reject');
    });

    // Line-manager approvals. Permission-gated *and* scoped to the manager's own
    // direct reports in the controller — the permission alone grants no access
    // to anyone else's request.
    Route::middleware('permission:approve-leave')->prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/', [LeaveApprovalController::class, 'index'])->name('index');
        Route::post('{leaveRequest}/approve', [LeaveApprovalController::class, 'approve'])->name('approve');
        Route::post('{leaveRequest}/reject', [LeaveApprovalController::class, 'reject'])->name('reject');
    });
});

// ---- Staff dashboard (admin + HR only) ----
// Locked to staff roles so an employee-role account can never reach the admin
// app or any company-wide data, even where it shares a permission.
Route::middleware(['auth', 'role:admin|hr'])->group(function () {

    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:view-dashboard')->name('dashboard');

    // Attendance module
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->middleware('permission:view-attendance')->name('index');
        Route::get('logs', [AttendanceController::class, 'logs'])->middleware('permission:view-attendance')->name('logs');
        Route::get('report', [AttendanceController::class, 'report'])->middleware('permission:view-reports')->name('report');
        Route::get('report/pdf', [AttendanceController::class, 'exportPdf'])->middleware('permission:export-reports')->name('report.pdf');
        Route::get('report/excel', [AttendanceController::class, 'exportExcel'])->middleware('permission:export-reports')->name('report.excel');
    });

    // HR Reporting — analytics variants (late / outliers / department)
    Route::prefix('reports')->name('reports.')->middleware('permission:view-reports')->group(function () {
        Route::get('late', [ReportController::class, 'late'])->name('late');
        Route::get('outliers', [ReportController::class, 'outliers'])->name('outliers');
        Route::get('department', [ReportController::class, 'department'])->name('department');
    });

    // Employees (+ bulk import)
    Route::get('employees/import', [EmployeeController::class, 'importForm'])->middleware('permission:import-employees')->name('employees.import');
    Route::get('employees/import/template', [EmployeeController::class, 'importTemplate'])->middleware('permission:import-employees')->name('employees.import.template');
    Route::post('employees/import', [EmployeeController::class, 'import'])->middleware('permission:import-employees')->name('employees.import.store');
    Route::resource('employees', EmployeeController::class)->middleware('permission:manage-employees');

    // Departments / Designations
    Route::resource('departments', DepartmentController::class)->except(['create', 'show', 'edit'])->middleware('permission:manage-departments');
    Route::resource('designations', DesignationController::class)->except(['create', 'show', 'edit'])->middleware('permission:manage-designations');

    // Leave — company-wide register and the final approval step.
    Route::get('leave', [LeaveController::class, 'index'])
        ->middleware('permission:manage-leave')->name('leave.index');
    Route::middleware('permission:approve-leave')->group(function () {
        Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
        Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
    });

    // Leave — configuration (types, holidays) and balance administration.
    Route::middleware('permission:manage-leave')->group(function () {
        Route::resource('leave-types', LeaveTypeController::class)
            ->parameters(['leave-types' => 'leaveType'])
            ->except(['create', 'show', 'edit']);

        // The holiday calendar drives what leave costs and what counts as an
        // absence, so it sits with leave rather than under general settings.
        Route::resource('holidays', HolidayController::class)
            ->except(['create', 'show', 'edit']);

        Route::get('leave-balances', [LeaveBalanceController::class, 'index'])->name('leave-balances.index');
        Route::post('leave-balances/generate', [LeaveBalanceController::class, 'generate'])->name('leave-balances.generate');
        Route::put('leave-balances/{leaveBalance}', [LeaveBalanceController::class, 'update'])->name('leave-balances.update');
        Route::post('leave-balances/{leaveBalance}/recalculate', [LeaveBalanceController::class, 'recalculate'])->name('leave-balances.recalculate');
    });

    // Shifts & Schedule
    Route::middleware('permission:manage-shifts')->group(function () {
        Route::get('shifts/roster', [ShiftController::class, 'roster'])->name('shifts.roster');
        Route::post('shifts/roster', [ShiftController::class, 'saveRoster'])->name('shifts.roster.save');
        Route::post('shifts/roster/rotation', [ShiftController::class, 'generateRotation'])->name('shifts.roster.rotation');
        Route::post('shifts/roster/publish', [ShiftController::class, 'publishRoster'])->name('shifts.roster.publish');

        // Company-wide swap register. HR can sanction a swap between two people
        // whose managers differ, which a single manager cannot.
        Route::get('shift-swaps', [ShiftSwapAdminController::class, 'index'])->name('shift-swaps.index');
        Route::post('shift-swaps/{swap}/approve', [ShiftSwapAdminController::class, 'approve'])->name('shift-swaps.approve');
        Route::post('shift-swaps/{swap}/reject', [ShiftSwapAdminController::class, 'reject'])->name('shift-swaps.reject');
    });
    Route::resource('shifts', ShiftController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('permission:manage-shifts');

    // Company / Offices (admin only — HR lacks these permissions)
    Route::middleware('permission:manage-company')->group(function () {
        Route::get('company', [CompanyController::class, 'index'])->name('company.index');
        Route::put('company', [CompanyController::class, 'update'])->name('company.update');
    });
    Route::middleware('permission:manage-offices')->group(function () {
        Route::resource('offices', OfficeController::class)->except(['create', 'show', 'edit']);
    });

    // Administration (admin only)
    Route::middleware('permission:manage-roles')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
    Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:manage-settings')->name('settings.index');

    // Profile (any authenticated staff user)
    Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});
