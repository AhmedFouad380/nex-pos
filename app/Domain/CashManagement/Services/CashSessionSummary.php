<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Payment;
use App\Domain\Sales\Enums\SaleStatus;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * X / Z report of a shift: what should be in the drawer and the takings per payment method.
 * expected cash = opening float + cash received − cash paid out + cash in − cash out
 */
final class CashSessionSummary
{
    /**
     * @return array{opening: string, cash_in_payments: string, cash_out_payments: string, cash_in: string, cash_out: string,
     *               expected_cash: string, by_method: list<array{name: string, in: string, out: string}>, sales_count: int, sales_total: string}
     */
    public function summarize(CashSession $session): array
    {
        $payments = Payment::query()->where('cash_session_id', $session->id)->whereNull('voided_at');

        $cashPayments = (clone $payments)->whereHas('paymentMethod', fn ($q) => $q->where('type', PaymentMethodType::Cash));
        $cashIn = Decimal::of((clone $cashPayments)->where('direction', PaymentDirection::In)->sum('amount'));
        $cashOut = Decimal::of((clone $cashPayments)->where('direction', PaymentDirection::Out)->sum('amount'));
        $movementIn = Decimal::of($session->movements()->where('direction', PaymentDirection::In)->sum('amount'));
        $movementOut = Decimal::of($session->movements()->where('direction', PaymentDirection::Out)->sum('amount'));

        $byMethod = (clone $payments)->toBase() // aggregated rows, not Payment models
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->groupBy('payment_methods.id', 'payment_methods.name')
            ->select('payment_methods.name',
                DB::raw("sum(case when direction = 'in' then amount else 0 end) as total_in"),
                DB::raw("sum(case when direction = 'out' then amount else 0 end) as total_out"))
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'in' => Decimal::toDb($r->total_in), 'out' => Decimal::toDb($r->total_out)])
            ->all();

        $sales = $session->sales()->whereNull('voided_at')->where('status', SaleStatus::Final);

        return [
            'opening' => Decimal::toDb($session->opening_float),
            'cash_in_payments' => Decimal::toDb($cashIn),
            'cash_out_payments' => Decimal::toDb($cashOut),
            'cash_in' => Decimal::toDb($movementIn),
            'cash_out' => Decimal::toDb($movementOut),
            'expected_cash' => Decimal::toDb(Decimal::of($session->opening_float)->plus($cashIn)->minus($cashOut)->plus($movementIn)->minus($movementOut)),
            'by_method' => $byMethod,
            'sales_count' => (clone $sales)->count(),
            'sales_total' => Decimal::toDb((clone $sales)->sum('total')),
        ];
    }

    public function expectedCash(CashSession $session): BigDecimal
    {
        return Decimal::of($this->summarize($session)['expected_cash']);
    }
}
