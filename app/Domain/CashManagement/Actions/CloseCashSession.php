<?php

namespace App\Domain\CashManagement\Actions;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\CashManagement\Services\ShiftCloseNotifier;
use App\Domain\Finance\Services\Posting;
use App\Domain\Sales\Enums\SaleStatus;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Closes a shift: compares the counted cash with what the system expects, records the difference and tells the managers. */
final class CloseCashSession
{
    public function __construct(private readonly CashSessionSummary $summary) {}

    /** @param array<string, int> $denominations note value => count */
    public function handle(CashSession $session, string|int|float $countedCash, array $denominations = [], int $cardSlips = 0,
        int $cheques = 0, ?string $note = null): CashSession
    {
        if ($session->status !== CashSessionStatus::Open) {
            throw new DomainException(__('cash.errors.session_closed'));
        }
        if ($session->sales()->where('status', SaleStatus::Suspended)->exists()) {
            throw new DomainException(__('cash.errors.suspended_sales'));
        }

        $closed = DB::transaction(function () use ($session, $countedCash, $denominations, $cardSlips, $cheques, $note) {
            $expected = $this->summary->expectedCash($session);
            $counted = Decimal::of($countedCash);

            $session->update([
                'status' => CashSessionStatus::Closed,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'expected_cash' => Decimal::toDb($expected),
                'counted_cash' => Decimal::toDb($counted),
                'difference' => Decimal::toDb($counted->minus($expected)),
                'denominations' => array_filter($denominations),
                'card_slips_count' => $cardSlips,
                'cheques_count' => $cheques,
                'closing_note' => $note,
            ]);

            app(Posting::class)->sync($session);

            return $session;
        });

        // the managers learn whether the drawer matched; a failed notification never undoes the close
        rescue(fn () => app(ShiftCloseNotifier::class)->closed($closed));

        return $closed;
    }
}
