<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Cash taken from or put into a branch's box while a shift is open there belongs to that shift
 * (an advance, a voucher, an expense or a return made on the admin pages), so the drawer's expected cash
 * follows it and the shift page lists it. A branch has one open shift at a time.
 * Without an open shift a cashier may not move the branch's cash at all; only those who may close
 * other people's shifts (a manager, the accountant) can, e.g. after the day is closed.
 */
final class ShiftLink
{
    private static bool $suspended = false;

    /** Runs $callback without linking, e.g. the old-system import, whose payments belong to no shift. */
    public static function without(callable $callback): mixed
    {
        $before = self::$suspended;
        self::$suspended = true;
        try {
            return $callback();
        } finally {
            self::$suspended = $before;
        }
    }

    /**
     * The open shift the money belongs to (see openShiftFor), or null when it belongs to none.
     *
     * @throws DomainException a cashier moving the branch's cash while no shift of that day is open there
     */
    public function resolve(?int $locationId, PaymentMethod $method, int $accountId, ?int $branchAccountId, CarbonInterface|string|null $paidAt): ?int
    {
        $sessionId = $this->openShiftFor($locationId, $method, $accountId, $branchAccountId, $paidAt);
        $user = auth()->user();
        if ($sessionId === null && ! self::$suspended && $user !== null && $this->isBranchCash($locationId, $method, $accountId, $branchAccountId)
            && ! $user->can('cash_register.close_others')) {
            throw new DomainException(__('cash.errors.no_open_shift', ['branch' => Location::query()->whereKey($locationId)->value('name')]));
        }

        return $sessionId;
    }

    /**
     * The open shift of the branch, when the money is cash moved through the branch's own cash account
     * and dated on or after the day the shift was opened.
     */
    public function openShiftFor(?int $locationId, PaymentMethod $method, int $accountId, ?int $branchAccountId, CarbonInterface|string|null $paidAt): ?int
    {
        if (self::$suspended || ! $this->isBranchCash($locationId, $method, $accountId, $branchAccountId)) {
            return null;
        }
        $session = CashSession::query()->where('status', CashSessionStatus::Open)
            ->whereHas('cashRegister', fn ($query) => $query->where('location_id', $locationId))
            ->latest('opened_at')->first();
        if ($session === null) {
            return null;
        }
        $date = Carbon::parse($paidAt ?? now());

        return $date->toDateString() >= $session->opened_at->toDateString() ? $session->id : null;
    }

    private function isBranchCash(?int $locationId, PaymentMethod $method, int $accountId, ?int $branchAccountId): bool
    {
        return $locationId !== null && $method->type === PaymentMethodType::Cash && $branchAccountId !== null && $accountId === $branchAccountId;
    }
}
