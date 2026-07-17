<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePortalController;
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

// ---- Employee self-service portal (employee role only) ----
// A locked-down area: employees can check in/out and view their own attendance,
// and nothing else in the application.
Route::middleware(['auth', 'role:employee'])->prefix('employee')->name('employee.')->group(function () {
    Route::get('dashboard', [EmployeePortalController::class, 'dashboard'])->name('dashboard');
    Route::post('scan', [EmployeePortalController::class, 'scan'])->name('scan');
});

// ---- Full-screen kiosk display (unattended tablet) ----
// Permanent signed URLs — no login required, cannot be enumerated/forged.
Route::middleware('signed')->group(function () {
    Route::get('kiosk/{office}/display', [AttendanceController::class, 'kioskDisplay'])
        ->name('attendance.kiosk.display');
    Route::get('kiosk/{office}/display/qr', [AttendanceController::class, 'kioskDisplayQr'])
        ->name('attendance.kiosk.display.qr');
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
        // Kiosk + scanner operations
        Route::middleware('permission:manage-attendance')->group(function () {
            Route::get('kiosk', [AttendanceController::class, 'kiosk'])->name('kiosk');
            Route::get('kiosk/{office}/qr', [AttendanceController::class, 'qrToken'])->name('qr');
            Route::get('scanner', [AttendanceController::class, 'scanner'])->name('scanner');
            Route::post('scan', [AttendanceController::class, 'scan'])->name('scan');
        });
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

    // Shifts & Schedule
    Route::get('shifts/roster', [ShiftController::class, 'roster'])->middleware('permission:manage-shifts')->name('shifts.roster');
    Route::resource('shifts', ShiftController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('permission:manage-shifts');

    // Company / Offices (admin only — HR lacks these permissions)
    Route::middleware('permission:manage-company')->group(function () {
        Route::get('company', [CompanyController::class, 'index'])->name('company.index');
        Route::put('company', [CompanyController::class, 'update'])->name('company.update');
    });
    Route::middleware('permission:manage-offices')->group(function () {
        Route::post('offices/{office}/rotate-secret', [OfficeController::class, 'rotateSecret'])->name('offices.rotate');
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
