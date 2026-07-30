<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

// ---- Guest / Auth ----
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login']);
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->homeRoute());
    }
    return redirect()->route('login');
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

    // Leave — configuration (types).
    Route::resource('leave-types', LeaveTypeController::class)
        ->parameters(['leave-types' => 'leaveType'])
        ->except(['create', 'show', 'edit'])
        ->middleware('permission:manage-leave');

    // Shifts & Schedule
    Route::get('shifts/roster', [ShiftController::class, 'roster'])->middleware('permission:manage-shifts')->name('shifts.roster');
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
