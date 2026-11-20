<?php

namespace App\Support;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\Payment;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * السنة المالية المعروضة: every list of documents and every report shows one fiscal year at a time —
 * the current one, or an older one picked from the year switcher in the top bar (kept in the session).
 * A year is named by the calendar year it starts in; it starts on the month set in the business settings.
 */
final class FiscalYear
{
    public const SESSION_KEY = 'fiscal_year';

    /** The date column that places each kind of document in a year. */
    public const COLUMNS = [
        Sale::class => 'sale_date',
        SaleReturn::class => 'return_date',
        Purchase::class => 'purchase_date',
        PurchaseReturn::class => 'return_date',
        PurchaseOrder::class => 'order_date',
        Payment::class => 'paid_at',
        Expense::class => 'expense_date',
        JournalEntry::class => 'entry_date',
        AccountTransfer::class => 'transferred_at',
        StockAdjustment::class => 'adjusted_at',
        StockTransfer::class => 'transfer_date',
        CashSession::class => 'opened_at',
        EmployeeAdvance::class => 'advance_date',
        PayrollRun::class => 'period',
    ];

    public static function startMonth(): int
    {
        $month = (int) rescue(fn () => app(GeneralSettings::class)->fy_start_month, 1, false);

        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    /** The fiscal year a date falls in. */
    public static function of(CarbonInterface $date): int
    {
        return $date->month >= self::startMonth() ? $date->year : $date->year - 1;
    }

    public static function current(): int
    {
        return self::of(now());
    }

    /** The year being looked at: the one picked in the switcher, else the current one. */
    public static function selected(): int
    {
        $year = session(self::SESSION_KEY);

        return is_numeric($year) ? (int) $year : self::current();
    }

    public static function isCurrent(): bool
    {
        return self::selected() === self::current();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last moment of the year */
    public static function range(?int $year = null): array
    {
        $start = CarbonImmutable::create($year ?? self::selected(), self::startMonth(), 1)->startOfDay();

        return [$start, $start->addYear()->subDay()->endOfDay()];
    }

    /** Default "to" of reports: the year's end, or today while the year is running. */
    public static function reportEnd(?int $year = null): CarbonImmutable
    {
        $end = self::range($year)[1];

        return $end->isFuture() ? CarbonImmutable::now() : $end;
    }

    public static function label(int $year): string
    {
        return self::startMonth() === 1 ? (string) $year : $year.'/'.($year + 1);
    }

    /** @return array<int, string> every year with documents, newest first (always including the current one) */
    public static function years(): array
    {
        $first = rescue(fn () => collect([
            DB::table('sales')->min('sale_date'),
            DB::table('journal_entries')->min('entry_date'),
            DB::table('purchases')->min('purchase_date'),
        ])->filter()->min(), null, false);
        $from = $first ? self::of(CarbonImmutable::parse($first)) : self::current();
        $to = max(self::current(), self::selected());

        return collect(range($to, min($from, $to)))->mapWithKeys(fn (int $y) => [$y => self::label($y)])->all();
    }

    /** @param class-string $model */
    public static function columnFor(string $model): ?string
    {
        return self::COLUMNS[$model] ?? null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $column, ?int $year = null): Builder
    {
        [$start, $end] = self::range($year);
        $column = $query->getModel()->qualifyColumn($column);

        return $query->whereBetween($column, [$start->toDateTimeString(), $end->toDateTimeString()]);
    }
}
