<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveApprovalController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ScheduleController;
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

// Login is throttled harder than the rest: it is the one endpoint where
// guessing is the attack, and it is reachable without a token.
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('api.auth.logout-all');
    Route::get('auth/devices', [AuthController::class, 'devices'])->name('api.auth.devices');

    // Attendance. The punch itself is throttled on top of the service's own
    // cooldown: the cooldown stops a double tap, this stops a loop.
    Route::post('attendance/check', [AttendanceController::class, 'check'])
        ->middleware('throttle:20,1')
        ->name('api.attendance.check');
    Route::get('attendance/today', [AttendanceController::class, 'today'])->name('api.attendance.today');
    Route::get('attendance/history', [AttendanceController::class, 'history'])->name('api.attendance.history');

    // Leave — the employee's own.
    Route::get('leave/balances', [LeaveController::class, 'balances'])->name('api.leave.balances');
    Route::get('leave/requests', [LeaveController::class, 'index'])->name('api.leave.index');
    Route::post('leave/requests', [LeaveController::class, 'store'])->name('api.leave.store');
    Route::get('leave/requests/{leaveRequest}', [LeaveController::class, 'show'])->name('api.leave.show');
    Route::post('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('api.leave.cancel');

    // The line-manager inbox. Permission-gated *and* scoped to the manager's own
    // reports in the controller — the permission alone reaches nobody else.
    Route::middleware('permission:approve-leave')->group(function () {
        Route::get('leave/approvals', [LeaveApprovalController::class, 'index'])->name('api.leave.approvals');
        Route::post('leave/approvals/{leaveRequest}/approve', [LeaveApprovalController::class, 'approve'])->name('api.leave.approve');
        Route::post('leave/approvals/{leaveRequest}/reject', [LeaveApprovalController::class, 'reject'])->name('api.leave.reject');
    });

    Route::get('schedule', [ScheduleController::class, 'index'])->name('api.schedule');

    Route::get('profile', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('api.profile.password');
});
