<?php

namespace App\Http\Middleware;

use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Services\DevicePairing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Support\CauserResolver;
use Symfony\Component\HttpFoundation\Response;

/** After auth:sanctum — the token must belong to a paired, not revoked wallet device. Records that it was seen. */
class AuthenticateWalletDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = Auth::guard('sanctum')->user(); // the phone, not a User
        abort_unless($device instanceof WalletDevice && $device->revoked_at === null && $device->tokenCan(DevicePairing::ABILITY), 401);

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        // activity log: the phone did it — or the employee whose PIN was checked (see WalletDeviceController::userByPin)
        return app(CauserResolver::class)->withCauser($device, fn () => $next($request));
    }
}
