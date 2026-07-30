<?php

namespace App\Http\Controllers\Api;

use App\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where to reach this person's phone.
 *
 * The app registers on launch and again whenever the OS hands it a new token,
 * which it does on its own schedule — so this endpoint is called repeatedly
 * with the same values and has to be safe to repeat.
 */
class DeviceController extends ApiController
{
    /** Handsets currently registered to the caller. */
    public function index(Request $request): JsonResponse
    {
        return $this->ok([
            'devices' => $request->user()->pushDevices()
                ->latest('last_seen_at')
                ->get()
                ->map(fn (PushDevice $device) => [
                    'id'           => $device->id,
                    'platform'     => $device->platform,
                    'device_name'  => $device->device_name,
                    'app_version'  => $device->app_version,
                    'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    // Never the token itself. It is a credential for sending to
                    // this handset, and listing it would put every one of the
                    // person's devices in a payload any of them can read.
                ])->values(),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'       => 'required|string|max:255',
            'platform'    => 'required|in:' . implode(',', PushDevice::PLATFORMS),
            'device_name' => 'nullable|string|max:100',
            'app_version' => 'nullable|string|max:30',
        ]);

        $device = PushDevice::register($request->user(), $data);

        return $this->ok([
            'device' => [
                'id'           => $device->id,
                'platform'     => $device->platform,
                'device_name'  => $device->device_name,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
            'message' => 'Device registered for notifications.',
        ]);
    }

    /**
     * Stop sending to a handset.
     *
     * Takes the token rather than a row id: the app knows its own token, and
     * asking it to remember an id we assigned is one more thing to get out of
     * step. An unknown token is not an error — the caller wanted it gone, and
     * it is.
     */
    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:255']);

        // Scoped to the caller so a token cannot be used to silence somebody
        // else's phone.
        $removed = $request->user()->pushDevices()->where('token', $data['token'])->delete();

        return $this->ok([
            'removed' => (int) $removed,
            'message' => 'Device will no longer receive notifications.',
        ]);
    }
}
