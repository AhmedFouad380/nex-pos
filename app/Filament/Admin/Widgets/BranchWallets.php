<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Services\WalletUsage;
use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** محافظ الفروع: each branch's lines with the balance the phone reports, what is used of the limits and what can still be sent. */
class BranchWallets extends Widget
{
    use DashboardScope;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.branch-wallets';

    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('wallet.view') && Wallet::query()->where('is_active', true)->exists();
    }

    /**
     * @return Collection<int|string, array{lines: array<int, array{wallet: Wallet, usage: array{out_today: float, out_month: float, in_today: float, in_month: float,
     *     available_out: ?float, available_in: ?float, out_percent: ?int, level: string}}>, balance: float, available: ?float}> by branch name */
    public function branches(): Collection
    {
        $wallets = Wallet::query()->with('location')->where('is_active', true)
            ->when($this->locationIds() !== null, fn ($q) => $q->whereIn('location_id', $this->locationIds()))
            ->orderBy('location_id')->orderBy('sim_slot')->orderBy('name')->get();
        $usage = app(WalletUsage::class)->for($wallets);

        return $wallets->groupBy(fn (Wallet $w) => $w->location->name ?? __('No branch'))
            ->map(function (Collection $lines) use ($usage) {
                $available = $lines->map(fn (Wallet $w) => $usage[$w->id]['available_out'])->filter(fn ($v) => $v !== null);

                return [
                    'lines' => $lines->map(fn (Wallet $w) => ['wallet' => $w, 'usage' => $usage[$w->id]])->values()->all(),
                    'balance' => (float) $lines->sum(fn (Wallet $w) => (float) $w->last_balance),
                    'available' => $available->isEmpty() ? null : (float) $available->sum(),
                ];
            });
    }
}
