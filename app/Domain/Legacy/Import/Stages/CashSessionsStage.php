<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Finance\Services\Posting;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POS shifts (POSTransactions). Closed ones carry their closing figures (POSClosedDetails): expected = net cash
 * of the shift, counted = what the cashier handed over (kept in the note; the money moved with the closing voucher).
 * Shifts still open in the old system are closed at their start, without a difference (nobody counted them).
 * Runs before the documents, which point to their shift.
 */
final class CashSessionsStage implements Stage
{
    public function name(): string
    {
        return 'cash sessions';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $operators = collect($package->all('Operators'))->mapWithKeys(fn ($o) => [(string) $o['OperatorId'] => (string) $o['UserId']]);
        $closings = collect($package->all('POSClosedDetails'))->groupBy(fn ($c) => ImportContext::key($c['BranchId'], $c['PTId']));
        $closeDates = collect($package->all('FinancialTransactions'))->where('JVType', 29)
            ->mapWithKeys(fn ($f) => [ImportContext::key($f['BranchId'], $f['FYId'], $f['FTId']) => $f['FTDate']]);
        $fallbackUser = User::query()->orderBy('id')->value('id');

        DB::transaction(function () use ($package, $context, $operators, $closings, $closeDates, $fallbackUser) {
            foreach ($package->all('POSTransactions') as $row) {
                $key = ImportContext::key($row['BranchId'], $row['PTId']);
                $register = $context->newId('POS', ImportContext::key($row['BranchId'], $row['POSId']));
                if (! $register) {
                    $context->warn(__('legacy.warnings.shift_without_drawer', ['id' => $key]));

                    continue;
                }
                $opened = Carbon::parse($row['StartDate']);
                $closing = $closings->get($key, collect());
                $expected = $closing->reduce(fn ($c, $r) => $c->plus(Decimal::of($r['NetValue'])), Decimal::of(0));
                $counted = $closing->reduce(fn ($c, $r) => $c->plus(Decimal::of($r['PaymentValue'])), Decimal::of(0));
                $closedAt = filled($row['FTId']) ? $closeDates->get(ImportContext::key($row['BranchId'], $row['FYId'], $row['FTId'])) : null;
                $user = $context->newId('Users', (string) ($operators[(string) $row['OperatorId']] ?? '')) ?? $fallbackUser;

                $session = CashSession::query()->find($context->newId('POSTransactions', $key)) ?? new CashSession;
                $session->fill([
                    'cash_register_id' => $register,
                    'user_id' => $user,
                    'status' => CashSessionStatus::Closed,
                    'opened_at' => $opened,
                    'opening_float' => 0,
                    'closed_at' => $closedAt ? Carbon::parse($closedAt) : $opened,
                    'closed_by' => $user,
                    'expected_cash' => Decimal::toDb($expected),
                    // the hand-over itself comes in with the old closing voucher (JournalsStage), which also leaves any
                    // shortage on the cashier's custody — so the shift records its figures without posting them again
                    'counted_cash' => Decimal::toDb($closing->isEmpty() ? $expected : $counted),
                    'difference' => 0,
                    'closing_note' => $closing->isEmpty() ? __('legacy.shift_left_open')
                        : __('legacy.shift_figures', ['expected' => Decimal::toDb($expected, 2), 'counted' => Decimal::toDb($counted, 2)]),
                ])->save();
                $context->remember('POSTransactions', $key, $session);
                app(Posting::class)->sync($session);
                $context->count($this->name(), $closing->isEmpty() ? 'left open' : 'closed');
            }
        });
    }
}
