<?php

namespace App\Http\Controllers;

use App\Models\QrDisplay;
use App\Services\QrAttendanceService;
use App\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The page on the office screen itself (A4.21).
 *
 * No sign-in: both routes are signed links naming one screen, so the tablet
 * holds nobody's session and a revoked screen stops at the next poll.
 */
class QrDisplayScreenController extends Controller
{
    public function __construct(protected QrAttendanceService $qr) {}

    public function show(QrDisplay $display)
    {
        abort_if($display->isRevoked(), 410, 'This screen has been switched off. Ask HR for a new link.');

        return view('attendance.qr-screen', [
            'display'    => $display->load('office', 'company'),
            'currentUrl' => URL::signedRoute('qr-display.current', ['display' => $display->id]),
        ]);
    }

    /**
     * Polled about once a second. Sends the code's value only when it changes;
     * see QrAttendanceService::currentFor.
     */
    public function current(Request $request, QrDisplay $display): JsonResponse
    {
        if ($display->isRevoked()) {
            return response()->json(['ok' => false, 'revoked' => true], 410);
        }

        // A header rather than a query parameter, because the URL is signed.
        $showing = (int) $request->header('X-Showing') ?: null;
        $state   = $this->qr->currentFor($display, $showing);

        return response()->json([
            'ok'         => true,
            'changed'    => $state['changed'],
            'token_id'   => $state['token_id'],
            'svg'        => $state['changed'] ? QrCode::svg($state['payload'], 420) : null,
            'expires_in' => $state['expires_in'],
            'last_scan'  => $this->qr->lastScanFor($display),
        ])->header('Cache-Control', 'no-store');
    }
}
