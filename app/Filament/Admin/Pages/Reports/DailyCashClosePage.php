<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\CashManagement\Actions\ReceiveShiftCash;
use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** تقفيل النقدية اليومي: the shifts closed in the period, what each drawer should have had, what was counted and who received it. */
class DailyCashClosePage extends ReportPage
{
    protected static string $permission = 'reports.payments';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'reports/daily-cash-close';

    public static function getNavigationLabel(): string
    {
        return __('Daily cash close');
    }

    /** @return Collection<int, CashSession> */
    private function shifts(): Collection
    {
        $filter = $this->filter();

        return CashSession::query()->where('status', CashSessionStatus::Closed)
            ->whereBetween('closed_at', [$filter->from->startOfDay(), $filter->to->endOfDay()])
            ->when($filter->locationIds !== null, fn ($q) => $q->whereHas('cashRegister', fn ($r) => $r->whereIn('location_id', $filter->locationIds)))
            ->with(['cashRegister.location', 'user', 'receiver', 'receivedAccount'])->orderBy('closed_at')->get();
    }

    public function cards(): array
    {
        $shifts = $this->shifts();
        $pending = $shifts->filter(fn (CashSession $s) => $s->awaitsReceipt());

        return [
            [__('Counted cash'), (float) $shifts->sum('counted_cash'), 'money'],
            [__('Shortage / overage'), (float) $shifts->sum('difference'), 'money', (float) $shifts->sum('difference') < 0 ? 'neg' : 'pos'],
            [__('Received'), (float) $shifts->sum('received_amount'), 'money'],
            [__('Awaiting receipt'), $pending->count().' · '.number_format((float) $pending->sum('counted_cash'), 2), 'text', $pending->isEmpty() ? 'pos' : 'neg'],
        ];
    }

    public function tables(): array
    {
        $rows = $this->shifts()->map(fn (CashSession $s) => [
            'date' => $s->closed_at->format('Y-m-d'),
            'location' => $s->cashRegister->location->name ?? '—',
            'cashier' => $s->user->name,
            'hours' => $s->opened_at->format('H:i').' → '.$s->closed_at->format('H:i'),
            'expected' => (float) $s->expected_cash,
            'counted' => (float) $s->counted_cash,
            'difference' => (float) $s->difference,
            'received' => $s->received_at ? (float) $s->received_amount : null,
            'kept' => $s->received_at ? (float) $s->kept_float : null,
            'shortfall' => $s->received_at ? (float) ReceiveShiftCash::shortfall($s) : null,
            'receiver' => $s->received_at ? $s->receiver?->name.' → '.($s->receivedAccount->name ?? '—') : ($s->awaitsReceipt() ? __('Awaiting receipt') : '—'),
        ])->all();

        return [['columns' => [
            'date' => [__('Date')], 'location' => [__('Location')], 'cashier' => [__('Cashier')], 'hours' => [__('Hours')],
            'expected' => [__('Expected in drawer'), 'money'], 'counted' => [__('Counted cash'), 'money'], 'difference' => [__('Difference'), 'money'],
            'received' => [__('Amount received'), 'money'], 'kept' => [__('Float left in the drawer'), 'money'],
            'shortfall' => [__('Not accounted for at receipt'), 'money'], 'receiver' => [__('Received by')],
        ], 'rows' => $rows, 'totals' => self::totals($rows, ['expected', 'counted', 'difference', 'received', 'kept', 'shortfall'], 'date')]];
    }
}
