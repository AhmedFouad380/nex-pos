<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\BranchCash;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Finance\Models\Account;
use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use Filament\Widgets\Widget;

/**
 * أرصدة المحلات: each branch's cash box as the books have it now, the shift open there and its expected cash,
 * and the closed shifts whose cash nobody has received yet.
 */
class BranchCashBalances extends Widget
{
    use DashboardScope;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.branch-cash-balances';

    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('accounts.view') || $user?->can('cash_register.close_others'));
    }

    /**
     * @return array{rows: list<array{location: Location, box: ?Account, balance: float, shift: ?CashSession, expected: ?float, pending: int, pending_cash: float}>,
     *     balance: float, pending: int, pending_cash: float}
     */
    public function data(): array
    {
        $cash = app(BranchCash::class);
        $locations = Location::query()->where('is_active', true)->orderBy('id')
            ->when($this->locationIds() !== null, fn ($q) => $q->whereKey($this->locationIds()))->get();
        $boxes = $locations->mapWithKeys(fn (Location $l) => [$l->id => $cash->boxFor($l->id)]);
        $balances = $cash->balances($boxes->filter()->unique()->values()->all());
        $accounts = Account::query()->whereKey($boxes->filter()->all())->get()->keyBy('id');
        $pending = CashSession::query()->awaitingReceipt()
            ->join('cash_registers', 'cash_registers.id', '=', 'cash_sessions.cash_register_id')
            ->groupBy('cash_registers.location_id')
            ->selectRaw('cash_registers.location_id, count(*) as n, coalesce(sum(counted_cash), 0) as cash')->get()->keyBy('location_id');

        $rows = [];
        foreach ($locations as $location) {
            $box = $boxes[$location->id];
            $shift = $cash->openShift($location->id);
            $rows[] = [
                'location' => $location,
                'box' => $box ? $accounts[$box] ?? null : null,
                'balance' => $box ? (float) (string) $balances[$box] : 0.0,
                'shift' => $shift,
                'expected' => $shift ? (float) app(CashSessionSummary::class)->summarize($shift)['expected_cash'] : null,
                'pending' => (int) ($pending[$location->id]->n ?? 0),
                'pending_cash' => (float) ($pending[$location->id]->cash ?? 0),
            ];
        }
        // a box shared by several branches counts once in the total
        $seen = [];
        $total = 0.0;
        foreach ($rows as $row) {
            if ($row['box'] && ! isset($seen[$row['box']->id])) {
                $seen[$row['box']->id] = true;
                $total += $row['balance'];
            }
        }

        return ['rows' => $rows, 'balance' => $total, 'pending' => array_sum(array_column($rows, 'pending')),
            'pending_cash' => array_sum(array_column($rows, 'pending_cash'))];
    }
}
