<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Services\GeneralLedger;
use App\Filament\Admin\Resources\Accounts\AccountResource;
use App\Filament\NavigationGroup;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** ميزان المراجعة: opening, period movement and closing balance of every account, in debit / credit columns. */
class TrialBalance extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.admin.trial-balance';

    public ?string $from = null;

    public ?string $to = null;

    public bool $hideZero = true;

    public bool $leavesOnly = false;

    public static function getNavigationLabel(): string
    {
        return __('Trial balance');
    }

    public function getTitle(): string
    {
        return __('Trial balance');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('reports.accounting');
    }

    public function mount(): void
    {
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    /** @return array{rows: Collection<int, array{account: Account, depth: int, opening: string, debit: string, credit: string, closing: string}>, totals: array<string, string>, balanced: bool} */
    public function report(): array
    {
        $rows = app(GeneralLedger::class)->trialBalance(
            $this->from ? Carbon::parse($this->from) : null,
            $this->to ? Carbon::parse($this->to) : null,
        );

        // totals come from the leaves only, so parents aren't counted twice
        $leaves = $rows->filter(fn ($r) => $r['account']->is_postable);
        $sum = fn (string $key, bool $positive) => Decimal::toDb($leaves->reduce(function (BigDecimal $carry, $r) use ($key, $positive) {
            $v = Decimal::of($r[$key]);

            return $carry->plus($positive ? BigDecimal::max($v, BigDecimal::zero()) : BigDecimal::max($v->negated(), BigDecimal::zero()));
        }, BigDecimal::zero()));
        $totals = [
            'opening_dr' => $sum('opening', true), 'opening_cr' => $sum('opening', false),
            'debit' => Decimal::toDb($leaves->reduce(fn (BigDecimal $c, $r) => $c->plus(Decimal::of($r['debit'])), BigDecimal::zero())),
            'credit' => Decimal::toDb($leaves->reduce(fn (BigDecimal $c, $r) => $c->plus(Decimal::of($r['credit'])), BigDecimal::zero())),
            'closing_dr' => $sum('closing', true), 'closing_cr' => $sum('closing', false),
        ];

        $visible = $rows
            ->when($this->leavesOnly, fn ($c) => $c->filter(fn ($r) => $r['account']->is_postable))
            ->when($this->hideZero, fn ($c) => $c->reject(fn ($r) => Decimal::of($r['opening'])->isZero()
                && Decimal::of($r['debit'])->isZero() && Decimal::of($r['credit'])->isZero()))
            ->values();

        return [
            'rows' => $visible,
            'totals' => $totals,
            'balanced' => Decimal::of($totals['closing_dr'])->isEqualTo(Decimal::of($totals['closing_cr']))
                && Decimal::of($totals['debit'])->isEqualTo(Decimal::of($totals['credit'])),
        ];
    }

    public function ledgerUrl(int $accountId): string
    {
        return AccountResource::getUrl('ledger', ['record' => $accountId]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()')];
    }
}
