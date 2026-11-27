<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Payment;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Sales\Enums\SaleStatus;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * X / Z report of a shift: what should be in the drawer and the takings per payment method.
 * expected cash = opening float + cash received − cash paid out + cash in − cash out
 * Besides the sales of the shift, its cash holds whatever was paid from or into the branch box while it was open
 * (advances, vouchers, expenses, returns): see ShiftLink.
 */
final class CashSessionSummary
{
    /**
     * @return array{opening: string, cash_in_payments: string, cash_out_payments: string, cash_in: string, cash_out: string,
     *               expected_cash: string, by_method: list<array{name: string, in: string, out: string}>, sales_count: int, sales_total: string,
     *               cash_sales: string, cash_received_other: string, cash_refunds: string, cash_paid_other: string}
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

        $byKind = $this->cashByKind($session);
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
            'cash_sales' => Decimal::toDb($byKind['sale']),
            'cash_received_other' => Decimal::toDb($cashIn->minus($byKind['sale'])),
            'cash_refunds' => Decimal::toDb($byKind['sale_return']),
            'cash_paid_other' => Decimal::toDb($cashOut->minus($byKind['sale_return'])),
        ];
    }

    /**
     * The shift's payments other than the tenders taken with its sales, newest first, each with its kind:
     * sale_return, employee_advance, expense, purchase, purchase_return, receipt (other money in) or payment (other money out).
     *
     * @return list<array{payment: Payment, kind: string}>
     */
    public function operations(CashSession $session): array
    {
        $payments = Payment::query()->where('cash_session_id', $session->id)->whereNull('voided_at')
            ->with(['contact', 'counterAccount', 'paymentMethod', 'creator', 'allocations.allocatable'])->latest('paid_at')->latest('id')->get();
        $advances = EmployeeAdvance::query()->whereIn('payment_id', $payments->modelKeys())->pluck('payment_id')->flip();

        return $payments->map(fn (Payment $payment) => ['payment' => $payment, 'kind' => $this->kind($payment, $advances->has($payment->id))])
            ->reject(fn (array $row) => $row['kind'] === 'sale')->values()->all();
    }

    private function kind(Payment $payment, bool $advance): string
    {
        if ($advance) {
            return 'employee_advance';
        }
        $type = $payment->allocations->first()?->allocatable_type;
        if ($type === 'sale') {
            // a tender taken with the invoice itself; a later receipt against the invoice is a receipt voucher
            $sale = $payment->allocations->first()->allocatable;

            return $sale && $sale->sale_date?->equalTo($payment->paid_at) ? 'sale' : 'receipt';
        }

        return in_array($type, ['sale_return', 'expense', 'purchase', 'purchase_return'], true)
            ? $type : ($payment->direction === PaymentDirection::In ? 'receipt' : 'payment');
    }

    /** @return array{sale: BigDecimal, sale_return: BigDecimal} cash of the sale tenders and of the sale refunds */
    private function cashByKind(CashSession $session): array
    {
        $cash = ['sale' => Decimal::of(0), 'sale_return' => Decimal::of(0)];
        $payments = Payment::query()->where('cash_session_id', $session->id)->whereNull('voided_at')
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', PaymentMethodType::Cash))
            ->whereHas('allocations', fn ($q) => $q->whereIn('allocatable_type', ['sale', 'sale_return']))
            ->with('allocations.allocatable')->get();
        foreach ($payments as $payment) {
            $kind = $payment->allocations->first()->allocatable_type === 'sale_return' ? 'sale_return' : $this->kind($payment, false);
            if (isset($cash[$kind])) {
                $cash[$kind] = $cash[$kind]->plus(Decimal::of($payment->amount));
            }
        }

        return $cash;
    }

    public function expectedCash(CashSession $session): BigDecimal
    {
        return Decimal::of($this->summarize($session)['expected_cash']);
    }
}
