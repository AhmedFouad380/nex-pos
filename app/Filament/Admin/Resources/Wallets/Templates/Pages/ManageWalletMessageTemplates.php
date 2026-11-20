<?php

namespace App\Filament\Admin\Resources\Wallets\Templates\Pages;

use App\Domain\Wallet\Actions\ReadWalletMessageAgain;
use App\Filament\Admin\Resources\Wallets\Templates\WalletMessageTemplateResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageWalletMessageTemplates extends ManageRecords
{
    protected static string $resource = WalletMessageTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->after(fn () => $this->readAgain()),
            Action::make('readAgain')->label(__('Read waiting messages again'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => auth()->user()?->can('wallet.manage'))
                ->action(fn () => $this->readAgain()),
        ];
    }

    /** New or fixed wording: messages nobody could read so far may be readable now. */
    public function readAgain(): void
    {
        $count = app(ReadWalletMessageAgain::class)->handle();
        Notification::make()->success()->title(__(':count messages understood now', ['count' => $count]))->send();
    }
}
