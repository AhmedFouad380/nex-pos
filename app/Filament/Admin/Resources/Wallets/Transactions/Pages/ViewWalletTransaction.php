<?php

namespace App\Filament\Admin\Resources\Wallets\Transactions\Pages;

use App\Domain\Wallet\Actions\ReadWalletMessageAgain;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\Wallets\Transactions\WalletTransactionActions;
use App\Filament\Admin\Resources\Wallets\Transactions\WalletTransactionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewWalletTransaction extends ViewRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['wallet', 'location', 'device', 'payment', 'confirmer', 'template']);
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->refreshRecord();

        return [
            WalletTransactionActions::confirm($refresh),
            Action::make('readAgain')->label(__('Read again'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (WalletTransaction $record) => in_array($record->status, [Status::Unparsed, Status::Pending], true)
                    && auth()->user()?->can('wallet.manage'))
                ->action(function (WalletTransaction $record) {
                    app(ReadWalletMessageAgain::class)->handle(WalletTransaction::query()->whereKey($record->id));
                    Notification::make()->success()->title(__('Saved'))->send();
                    $this->refreshRecord();
                }),
            WalletTransactionActions::reject($refresh),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
