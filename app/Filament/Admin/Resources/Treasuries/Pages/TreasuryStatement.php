<?php

namespace App\Filament\Admin\Resources\Treasuries\Pages;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\GeneralLedger;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Filament\Admin\Resources\CashSessions\CashSessionResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Treasuries\TreasuryResource;
use App\Filament\Admin\Support\VoucherResource;
use App\Support\FiscalYear;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;

/** كشف الخزنة: every movement of a box in a period, what it was, its document and the balance after it. */
class TreasuryStatement extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TreasuryResource::class;

    protected string $view = 'filament.admin.treasury-statement';

    public ?string $from = null;

    public ?string $to = null;

    public function mount(int|string $record): void
    {
        abort_unless(auth()->user()?->can('accounts.view'), 403);
        $this->record = $this->resolveRecord($record);
        // the current month while looking at this year, else the whole chosen year
        [$start] = FiscalYear::range();
        $this->from = (FiscalYear::isCurrent() ? now()->startOfMonth() : $start)->toDateString();
        $this->to = FiscalYear::reportEnd()->toDateString();
    }

    public function box(): Account
    {
        $record = $this->getRecord();

        return $record instanceof Account ? $record : throw new \LogicException('A statement needs a box.');
    }

    public function getTitle(): string
    {
        return __('Cash box statement').' — '.$this->box()->name;
    }

    /**
     * @return array{opening: string, closing: string, in: string, out: string,
     *     rows: list<array{date: string, kind: string, number: ?string, url: ?string, party: ?string, description: ?string, in: float, out: float, balance: string}>}
     */
    public function statement(): array
    {
        $ledger = app(GeneralLedger::class)->statement($this->box(), $this->from ? Carbon::parse($this->from) : null, $this->to ? Carbon::parse($this->to) : null);

        $ids = fn (string $type) => $ledger['rows']->where('source_type', $type)->pluck('source_id')->unique()->all();
        $payments = Payment::query()->whereKey($ids('payment'))->with(['allocations.allocatable', 'contact', 'counterAccount'])->get()->keyBy('id');
        $advances = EmployeeAdvance::query()->whereIn('payment_id', $payments->keys())->pluck('payment_id')->flip();
        $receipts = CashSession::query()->whereIn('account_transfer_id', $ids('account_transfer'))->pluck('id', 'account_transfer_id');
        $summary = app(CashSessionSummary::class);

        $rows = [];
        foreach ($ledger['rows'] as $row) {
            $kind = __('cash.statement_kinds.manual');
            $number = $row->number;
            $url = JournalEntryResource::getUrl('view', ['record' => $row->entry_id]);
            $party = $row->contact;
            if ($row->source_type === 'payment' && ($payment = $payments[$row->source_id] ?? null)) {
                $kind = __('cash.kinds.'.$summary->kind($payment, $advances->has($payment->id)));
                $number = $payment->number;
                $url = VoucherResource::urlFor($payment);
                $party ??= $payment->contact?->name ?? $payment->counterAccount?->name;
            } elseif ($row->source_type === 'account_transfer') {
                $kind = isset($receipts[$row->source_id]) ? __('cash.statement_kinds.shift_receipt') : __('cash.statement_kinds.transfer');
                if (isset($receipts[$row->source_id])) {
                    $url = CashSessionResource::getUrl('view', ['record' => $receipts[$row->source_id]]);
                }
            } elseif ($row->source_type === 'cash_session') {
                $kind = __('cash.statement_kinds.shift_difference');
                $url = CashSessionResource::getUrl('view', ['record' => $row->source_id]);
            } elseif ($row->source_type !== null) {
                $kind = __('statement.kinds.'.$row->source_type);
            }
            $rows[] = [
                'date' => Carbon::parse($row->entry_date)->format('Y-m-d'), 'kind' => $kind, 'number' => $number, 'url' => $url,
                'party' => $party, 'description' => $row->memo ?: $row->description,
                'in' => (float) $row->debit, 'out' => (float) $row->credit, 'balance' => $row->balance,
            ];
        }

        return ['opening' => $ledger['opening'], 'closing' => $ledger['closing'], 'in' => $ledger['debit'], 'out' => $ledger['credit'], 'rows' => $rows];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
            Action::make('back')->label(__('Back'))->color('gray')->url(TreasuryResource::getUrl()),
        ];
    }
}
