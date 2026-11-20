<?php

namespace App\Domain\CashManagement\Actions;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Models\CashSession;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Opens a shift for a cashier on a drawer. One open shift per drawer and per user. */
final class OpenCashSession
{
    public function handle(CashRegister $register, User $user, string|int|float $openingFloat = 0): CashSession
    {
        if (! $register->is_active) {
            throw new DomainException(__('cash.errors.register_inactive'));
        }

        return DB::transaction(function () use ($register, $user, $openingFloat) {
            CashRegister::query()->whereKey($register->id)->lockForUpdate()->first();

            $open = CashSession::query()->with('user')->where('cash_register_id', $register->id)->where('status', CashSessionStatus::Open)->first();
            if ($open !== null) {
                throw new DomainException(__('cash.errors.register_busy', ['register' => $register->location->name ?? $register->name,
                    'user' => $open->user->name ?? '—', 'since' => $open->opened_at->format('Y-m-d H:i')]));
            }
            if (CashSession::query()->where('user_id', $user->id)->where('status', CashSessionStatus::Open)->exists()) {
                throw new DomainException(__('cash.errors.user_has_session'));
            }

            return CashSession::query()->create([
                'cash_register_id' => $register->id,
                'user_id' => $user->id,
                'status' => CashSessionStatus::Open,
                'opened_at' => now(),
                'opening_float' => Decimal::toDb($openingFloat),
            ]);
        });
    }

    public static function currentFor(User $user): ?CashSession
    {
        return CashSession::query()->with('cashRegister.location')
            ->where('user_id', $user->id)->where('status', CashSessionStatus::Open)->first();
    }
}
