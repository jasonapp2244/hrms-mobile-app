<?php

use App\Http\Controllers\Api\AppStatusController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CrashReportController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\LeaveApprovalController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RegularisationController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| The mobile app's entry point. Prefixed 'api/v1' in bootstrap/app.php rather
| than the default 'api': an app already installed on someone's phone cannot be
| forced to upgrade, so v2 has to be able to run alongside v1 rather than
| replacing it under the same URLs.
|
| Every response carries `ok`. Errors carry `error` and `message` on top of it,
| built centrally in bootstrap/app.php so a client parses one shape.
|
*/

Route::get('ping', fn () => response()->json([
    'ok'      => true,
    'service' => config('app.name'),
    'version' => 'v1',
    'time'    => now()->toIso8601String(),
]))->name('api.ping');

// Whether this build may carry on (B6.6). Unauthenticated by necessity: it is
// the one question the app asks before it knows anything, and during a
// maintenance window it is the only endpoint that can explain why everything
// else is refusing. Left on the general limiter — it is a cheap read, and one
// call per launch and per resume is not a shape worth constraining further.
Route::get('app/status', [AppStatusController::class, 'show'])->name('api.app.status');

// Crashes the app did not survive (B6.5), delivered on the next launch.
// Unauthenticated for the same reason as the gate above, and a stronger one:
// the crash worth having is the one that stops the app opening, and an endpoint
// behind auth:sanctum would collect every crash except that one. The controller
// reads a token when the request carries one. Its own limiter, because it is a
// public write.
Route::post('app/crashes', [CrashReportController::class, 'store'])
    ->middleware('throttle:crash')
    ->name('api.app.crashes');

// Login is throttled harder than the rest: it is the one endpoint where
// guessing is the attack, and it is reachable without a token. The named
// limiters are defined in AppServiceProvider, where the reasoning for each
// one lives next to its numbers rather than as a bare 5,1 here.
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.auth.login');

// Unauthenticated by necessity — somebody who could authenticate would not need
// it. Shares the login limiter for that reason: it is the other door into the
// same account, and leaving it on the general ceiling would make it the cheaper
// one to hammer.
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:login')
    ->name('api.auth.forgot-password');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('api.auth.logout-all');
    Route::get('auth/devices', [AuthController::class, 'devices'])->name('api.auth.devices');

    // The notification history (B5.6). Not gated on an employee record, unlike
    // almost everything below: a notification is addressed to a *user*, and an
    // HR account with no employee row still receives document-expiry warnings.
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('api.notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('throttle:write')->name('api.notifications.read-all');
    // After read-all, or the literal segment is captured as an id.
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->middleware('throttle:write')->name('api.notifications.read');

    // Push registration. Not itself a notification channel — it records where
    // to send, so Phase 5 has somewhere to deliver to.
    Route::get('devices', [DeviceController::class, 'index'])->name('api.devices.index');
    Route::post('devices', [DeviceController::class, 'register'])
        ->middleware('throttle:write')->name('api.devices.register');
    Route::delete('devices', [DeviceController::class, 'unregister'])
        ->middleware('throttle:write')->name('api.devices.unregister');

    // Attendance. The punch is throttled on top of the service's own cooldown:
    // the cooldown stops a double tap, the limiter stops a loop.
    Route::post('attendance/check', [AttendanceController::class, 'check'])
        ->middleware('throttle:punch')
        ->name('api.attendance.check');
    // Breaks share the punch limiter rather than the write one: they write an
    // attendance row like a punch does, and a loop on this endpoint costs the
    // same as a loop on that one.
    Route::post('attendance/break', [AttendanceController::class, 'break'])
        ->middleware('throttle:punch')
        ->name('api.attendance.break');
    // Delivering punches made with no signal (B2.4). A batch, and on the punch
    // limiter: it writes attendance rows, and 50 of them in one call is still
    // one call — the ceiling is on how often a handset may try, not how much
    // it carries.
    Route::post('attendance/sync', [AttendanceController::class, 'sync'])
        ->middleware('throttle:punch')
        ->name('api.attendance.sync');
    Route::get('attendance/today', [AttendanceController::class, 'today'])->name('api.attendance.today');
    Route::get('attendance/history', [AttendanceController::class, 'history'])->name('api.attendance.history');

    // Regularisation (A4.13) — raising only. Deciding one voids a punch and
    // writes a replacement, which is manage-attendance and stays on the web.
    // A manager has no step in this chain: leave is manager-then-HR, a
    // correction is HR's alone.
    Route::get('attendance/regularisations', [RegularisationController::class, 'index'])
        ->name('api.regularisations.index');
    Route::post('attendance/regularisations', [RegularisationController::class, 'store'])
        ->middleware('throttle:write')->name('api.regularisations.store');
    Route::post('attendance/regularisations/{regularisation}/cancel', [RegularisationController::class, 'cancel'])
        ->middleware('throttle:write')->name('api.regularisations.cancel');

    // Leave — the employee's own.
    Route::get('leave/balances', [LeaveController::class, 'balances'])->name('api.leave.balances');
    Route::get('leave/requests', [LeaveController::class, 'index'])->name('api.leave.index');
    Route::post('leave/requests', [LeaveController::class, 'store'])
        ->middleware('throttle:write')->name('api.leave.store');
    Route::get('leave/requests/{leaveRequest}', [LeaveController::class, 'show'])->name('api.leave.show');
    // The supporting file (B4.1). Reachable by the person who attached it and
    // by their line manager — the controller checks which, because the route
    // takes a bound model and route-model binding is the leak.
    Route::get('leave/requests/{leaveRequest}/attachment', [LeaveController::class, 'attachment'])
        ->name('api.leave.attachment');
    Route::post('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])
        ->middleware('throttle:write')->name('api.leave.cancel');

    // The line-manager inbox. Permission-gated *and* scoped to the manager's own
    // reports in the controller — the permission alone reaches nobody else.
    Route::middleware('permission:approve-leave')->group(function () {
        Route::get('leave/approvals', [LeaveApprovalController::class, 'index'])->name('api.leave.approvals');
        Route::post('leave/approvals/{leaveRequest}/approve', [LeaveApprovalController::class, 'approve'])
            ->middleware('throttle:write')->name('api.leave.approve');
        Route::post('leave/approvals/{leaveRequest}/reject', [LeaveApprovalController::class, 'reject'])
            ->middleware('throttle:write')->name('api.leave.reject');

        // Who on my team is in today. Same gate as the inbox — a manager is
        // someone who approves for a team, and this answers for that same team.
        Route::get('team/attendance', [TeamController::class, 'attendance'])
            ->name('api.team.attendance');

        // The team's published roster (B7.3). Same gate and same team as the
        // line above; published only, so a manager never sees a draft their
        // staff cannot.
        Route::get('team/roster', [TeamController::class, 'roster'])
            ->name('api.team.roster');

        // Who on my team is off, and when (B4.6) — the month grid the web
        // dashboard has had since A6.7. Same gate and same team again: a
        // manager already reads each of these in the approval inbox above, so
        // this arranges what they can see by day rather than widening it.
        Route::get('team/leave-calendar', [TeamController::class, 'leaveCalendar'])
            ->name('api.team.leave-calendar');
    });

    // The employee's own documents (B3.7). No employee id in either route —
    // the vault is HR's, and the only thing the app can reach is the caller's
    // own shelf of it.
    Route::get('documents', [DocumentController::class, 'index'])->name('api.documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'download'])
        ->name('api.documents.download');

    // Who else works here (B3.8). The only endpoint that answers about other
    // people, so it carries the least: no PII beyond a job title, and contact
    // details only where the company has switched them on.
    Route::get('directory', [DirectoryController::class, 'index'])->name('api.directory.index');

    Route::get('schedule', [ScheduleController::class, 'index'])->name('api.schedule');

    Route::get('profile', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::put('profile', [ProfileController::class, 'update'])
        ->middleware('throttle:write')->name('api.profile.update');
    // The employee record rather than the account (B3.2): where they live and
    // who to call. A separate route because it is a separate table, and an
    // account with no employee row has nothing here to write.
    Route::put('profile/details', [ProfileController::class, 'updateDetails'])
        ->middleware('throttle:write')->name('api.profile.details');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:write')->name('api.profile.password');
});
