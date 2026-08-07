<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeAccountController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegularisationController;
use App\Http\Controllers\RegularisationRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportSubscriptionController;
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

    // The second factor at sign-in (A1.7). Under 'guest' because nobody is
    // signed in yet — the password was accepted and then deliberately dropped,
    // so the only thing carrying the half-finished attempt is the session.
    // Throttled: without it the six digits are guessable at machine speed.
    Route::get('two-factor', [LoginController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('two-factor', [LoginController::class, 'verify'])
        ->middleware('throttle:10,1')->name('two-factor.verify');
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Enrolling in two-factor, from an ordinary signed-in session.
Route::middleware('auth')->group(function () {
    Route::get('profile/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('profile/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::post('profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('two-factor.regenerate');
    Route::delete('profile/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
});

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

    // Own profile and own password. Here for the same reason as notifications:
    // this used to sit in the admin|hr group, which meant an employee or a
    // manager could not change their own password anywhere in the browser —
    // the one account action nobody should have to raise a ticket for. Every
    // action is scoped to auth()->user() inside the controller, so being
    // signed in is the whole authorisation.
    Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
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
    // Break start/end (A4.15). Which one it is comes from the day's punches,
    // not from the client, so a stale page cannot open a second break.
    Route::post('break', [EmployeePortalController::class, 'break'])->name('break');

    // Own leave: balances, apply, withdraw. Every action is scoped to the
    // signed-in employee inside the controller, so no permission is needed —
    // holding the employee role is the authorisation.
    Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveRequestController::class, 'store'])->name('leave.store');
    Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');

    // Own attendance corrections (A4.13). Raising one changes nothing by
    // itself; it asks HR to apply a correction. Scoped to the signed-in
    // employee in the controller, like the rest of the portal.
    Route::get('regularisations', [RegularisationRequestController::class, 'index'])->name('regularisations.index');
    Route::post('regularisations', [RegularisationRequestController::class, 'store'])->name('regularisations.store');
    Route::post('regularisations/{regularisation}/cancel', [RegularisationRequestController::class, 'cancel'])->name('regularisations.cancel');

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
    // Which panels this person keeps (A8.5). Same gate as the dashboard: it
    // only ever writes the viewer's own preference.
    Route::post('dashboard/widgets', [DashboardController::class, 'widgets'])
        ->middleware('permission:view-dashboard')->name('dashboard.widgets');

    // Attendance module
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->middleware('permission:view-attendance')->name('index');
        Route::get('logs', [AttendanceController::class, 'logs'])->middleware('permission:view-attendance')->name('logs');
        // The live board (A4.19). Same permission as the log — it shows the same
        // facts, one moment's worth rather than a history.
        Route::get('board', [AttendanceController::class, 'board'])->middleware('permission:view-attendance')->name('board');
        Route::get('report', [AttendanceController::class, 'report'])->middleware('permission:view-reports')->name('report');
        Route::get('report/pdf', [AttendanceController::class, 'exportPdf'])->middleware('permission:export-reports')->name('report.pdf');
        Route::get('report/excel', [AttendanceController::class, 'exportExcel'])->middleware('permission:export-reports')->name('report.excel');

        // Correcting the record (A4.12). Both gated on manage-attendance, not
        // view-attendance: an employee holds the latter for their own history
        // and must never be able to key in or strike out a punch.
        Route::middleware('permission:manage-attendance')->group(function () {
            Route::post('manual', [AttendanceController::class, 'storeManual'])->name('manual');
            Route::post('{log}/void', [AttendanceController::class, 'void'])->name('void');

            // Regularisation queue (A4.13). Same permission as keying a
            // correction in by hand, because approving one does exactly that.
            Route::get('regularisations', [RegularisationController::class, 'index'])->name('regularisations');
            Route::post('regularisations/{regularisation}/approve', [RegularisationController::class, 'approve'])->name('regularisations.approve');
            Route::post('regularisations/{regularisation}/reject', [RegularisationController::class, 'reject'])->name('regularisations.reject');
        });
    });

    // HR Reporting — analytics variants (late / outliers / department)
    Route::prefix('reports')->name('reports.')->middleware('permission:view-reports')->group(function () {
        Route::get('late', [ReportController::class, 'late'])->name('late');
        Route::get('outliers', [ReportController::class, 'outliers'])->name('outliers');
        Route::get('overtime', [ReportController::class, 'overtime'])->name('overtime');
        Route::get('payroll', [ReportController::class, 'payroll'])->name('payroll');
        Route::get('leave', [ReportController::class, 'leave'])->name('leave');
        Route::get('department', [ReportController::class, 'department'])->name('department');
        Route::get('weekly', [ReportController::class, 'weekly'])->name('weekly');
        Route::get('custom', [ReportController::class, 'custom'])->name('custom');
    });

    // Scheduled report delivery (A7.12). Gated on export-reports rather than
    // view-reports: arranging for a report to be posted out every month is
    // exporting it, just on a timer.
    Route::prefix('reports/scheduled')->name('report-subscriptions.')
        ->middleware('permission:export-reports')->group(function () {
            Route::get('/', [ReportSubscriptionController::class, 'index'])->name('index');
            Route::post('/', [ReportSubscriptionController::class, 'store'])->name('store');
            Route::put('{subscription}', [ReportSubscriptionController::class, 'update'])->name('update');
            Route::delete('{subscription}', [ReportSubscriptionController::class, 'destroy'])->name('destroy');
            Route::post('{subscription}/send', [ReportSubscriptionController::class, 'send'])->name('send');
        });

    // Employees (+ bulk import)
    Route::get('employees/import', [EmployeeController::class, 'importForm'])->middleware('permission:import-employees')->name('employees.import');
    Route::get('employees/import/template', [EmployeeController::class, 'importTemplate'])->middleware('permission:import-employees')->name('employees.import.template');
    Route::post('employees/import', [EmployeeController::class, 'import'])->middleware('permission:import-employees')->name('employees.import.store');

    // Roster export (A3.11) and the reporting hierarchy (A3.10). Both declared
    // before the resource so 'export' and 'org-chart' are not swallowed by
    // employees/{employee}.
    Route::get('employees/export', [EmployeeController::class, 'export'])
        ->middleware('permission:manage-employees')->name('employees.export');
    Route::get('employees/org-chart', [EmployeeController::class, 'orgChart'])
        ->middleware('permission:manage-employees')->name('employees.org-chart');

    // Joining and leaving checklists (A3.12). Ticking "building access revoked"
    // is a claim somebody may later be asked to stand behind, so it sits with
    // whoever owns the employee record.
    Route::middleware('permission:manage-employees')->group(function () {
        Route::get('checklists', [ChecklistController::class, 'templates'])->name('checklists.templates');
        Route::post('checklists', [ChecklistController::class, 'storeTemplate'])->name('checklists.templates.store');
        Route::put('checklists/{template}', [ChecklistController::class, 'updateTemplate'])->name('checklists.templates.update');
        Route::delete('checklists/{template}', [ChecklistController::class, 'destroyTemplate'])->name('checklists.templates.destroy');

        Route::get('employees/{employee}/checklist', [ChecklistController::class, 'forEmployee'])->name('checklists.employee');
        Route::post('employees/{employee}/checklist', [ChecklistController::class, 'generate'])->name('checklists.generate');
        Route::post('employees/{employee}/checklist/{item}/toggle', [ChecklistController::class, 'toggle'])->name('checklists.toggle');
        Route::delete('employees/{employee}/checklist/{item}', [ChecklistController::class, 'destroyItem'])->name('checklists.items.destroy');
    });
    // The document vault (A3.8). Downloads stream through the controller rather
    // than sitting under public/ — these are contracts and passport scans.
    Route::middleware('permission:manage-employees')->group(function () {
        Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->name('employees.documents.index');
        Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('employees.documents.store');
        Route::get('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'download'])->name('employees.documents.download');
        Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->name('employees.documents.destroy');
    });
    // Sign-in accounts for employees. `manage-employees` because onboarding is
    // HR's job; which roles they may actually grant is decided inside the
    // controller, where `manage-roles` gates the elevated ones.
    Route::middleware('permission:manage-employees')->group(function () {
        Route::post('employees/{employee}/account', [EmployeeAccountController::class, 'store'])->name('employees.account.store');
        Route::post('employees/{employee}/account/password', [EmployeeAccountController::class, 'resetPassword'])->name('employees.account.password');
        Route::post('employees/{employee}/account/role', [EmployeeAccountController::class, 'updateRole'])->name('employees.account.role');
        Route::post('employees/{employee}/account/toggle', [EmployeeAccountController::class, 'toggleActive'])->name('employees.account.toggle');
    });

    Route::resource('employees', EmployeeController::class)->middleware('permission:manage-employees');

    // Departments / Designations
    Route::resource('departments', DepartmentController::class)->except(['create', 'show', 'edit'])->middleware('permission:manage-departments');
    Route::resource('designations', DesignationController::class)->except(['create', 'show', 'edit'])->middleware('permission:manage-designations');

    // Leave — company-wide register and the final approval step.
    Route::get('leave', [LeaveController::class, 'index'])
        ->middleware('permission:manage-leave')->name('leave.index');
    // The month view (A6.7). manage-leave only, despite approvers being the
    // other obvious audience: this whole group is already behind role:admin|hr,
    // so adding |approve-leave advertised access a manager can never actually
    // reach — they hold the permission but not the role. Managers see conflicts
    // flagged on the request itself, in the portal, which is where they work.
    Route::get('leave/calendar', [LeaveController::class, 'calendar'])
        ->middleware('permission:manage-leave')->name('leave.calendar');
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

    // The working week and the attendance/security policies (A1.9, A2.8, A4.16),
    // and the security trail (A1.8). All admin-only: they decide how the system
    // treats everybody, and who tried to sign in as whom.
    Route::middleware('permission:manage-settings')->group(function () {
        Route::get('settings/policies', [PolicyController::class, 'edit'])->name('policies.edit');
        Route::put('settings/policies', [PolicyController::class, 'update'])->name('policies.update');
        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    });
});
