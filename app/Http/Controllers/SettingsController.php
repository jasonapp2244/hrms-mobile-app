<?php

namespace App\Http\Controllers;

use App\Services\QrTokenService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $qrWindow = QrTokenService::WINDOW_SECONDS;
        $qrGrace = QrTokenService::GRACE_WINDOWS;

        return view('settings.index', compact('qrWindow', 'qrGrace'));
    }
}
