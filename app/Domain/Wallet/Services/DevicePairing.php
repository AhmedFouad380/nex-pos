<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Models\WalletDevice;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pairing a phone: an admin opens "pair device" for a branch and gets a one-time code (shown as a QR);
 * the app scans it and trades the code for its own API token. Codes live 10 minutes and work once.
 */
final class DevicePairing
{
    public const TTL_MINUTES = 10;

    public const ABILITY = 'wallet-device';

    /** @return array{code: string, payload: string, expires_at: string} payload = what the QR encodes */
    public function issue(Location $location, User $by): array
    {
        $code = Str::upper(Str::random(10));
        Cache::put(self::key($code), ['location_id' => $location->id, 'user_id' => $by->id], now()->addMinutes(self::TTL_MINUTES));

        return [
            'code' => $code,
            'payload' => (string) json_encode(['type' => 'newpos-wallet', 'url' => url('/api/wallet'), 'code' => $code], JSON_UNESCAPED_SLASHES),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
        ];
    }

    /** @return array{device: WalletDevice, token: string} */
    public function redeem(string $code, string $name, ?string $platform = null, ?string $appVersion = null): array
    {
        $pending = Cache::pull(self::key(Str::upper(trim($code))))
            ?? throw new DomainException(__('wallet.errors.pairing_code'));

        return DB::transaction(function () use ($pending, $name, $platform, $appVersion) {
            // one phone per branch: pairing a new one retires the old (its messages stay)
            WalletDevice::query()->where('location_id', $pending['location_id'])->whereNull('revoked_at')->get()
                ->each(fn (WalletDevice $old) => $this->revoke($old));
            $device = WalletDevice::query()->create([
                'name' => mb_substr($name, 0, 255),
                'location_id' => $pending['location_id'],
                'platform' => $platform,
                'app_version' => $appVersion,
                'paired_by' => $pending['user_id'],
                'last_seen_at' => now(),
            ]);

            return ['device' => $device, 'token' => $device->createToken('wallet-app', [self::ABILITY])->plainTextToken];
        });
    }

    public function revoke(WalletDevice $device): void
    {
        $device->tokens()->delete();
        $device->update(['revoked_at' => now()]);
    }

    private static function key(string $code): string
    {
        return 'wallet-pairing:'.$code;
    }
}
