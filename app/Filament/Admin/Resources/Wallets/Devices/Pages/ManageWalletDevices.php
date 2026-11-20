<?php

namespace App\Filament\Admin\Resources\Wallets\Devices\Pages;

use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Services\DevicePairing;
use App\Filament\Admin\Resources\Wallets\Devices\WalletDeviceResource;
use App\Filament\Support\UserLocations;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\HtmlString;

/** "Link a phone": choose the branch, show a one-time QR code for the app to scan. */
class ManageWalletDevices extends ManageRecords
{
    protected static string $resource = WalletDeviceResource::class;

    /** @var array{code: string, payload: string, expires_at: string}|null */
    public ?array $pairing = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pair')->label(__('Link a phone'))->icon('heroicon-o-qr-code')
                ->visible(fn () => auth()->user()?->can('wallet.manage'))
                ->schema([
                    Select::make('location_id')->label(__('Location'))->required()->options(fn () => UserLocations::options())
                        ->default(fn () => UserLocations::first()),
                ])
                ->modalSubmitActionLabel(__('Show QR code'))
                ->action(function (array $data, Action $action) {
                    $user = auth()->user();
                    abort_unless($user instanceof User && array_key_exists((int) $data['location_id'], UserLocations::options()), 403);
                    $this->pairing = app(DevicePairing::class)->issue(Location::query()->findOrFail($data['location_id']), $user);
                    $this->replaceMountedAction('showQr');
                }),
        ];
    }

    public function showQrAction(): Action
    {
        return Action::make('showQr')->modalHeading(__('Scan with the wallet app'))
            ->modalContent(fn () => new HtmlString($this->pairing === null ? '' : '<div style="text-align:center">'
                .'<img alt="QR" style="width:260px;height:260px;margin:0 auto;background:#fff;padding:8px;border-radius:8px" src="'
                .(new QRCode(new QROptions(['outputBase64' => true, 'scale' => 8])))->render($this->pairing['payload']).'">'
                .'<p style="margin-top:12px">'.e(__('Or type this code in the app:')).' <strong style="font-size:1.3em;letter-spacing:2px">'
                .e($this->pairing['code']).'</strong></p>'
                .'<p style="opacity:.7">'.e(__('Valid for :minutes minutes, for one phone.', ['minutes' => DevicePairing::TTL_MINUTES])).'</p></div>'))
            ->modalSubmitAction(false)->modalCancelActionLabel(__('Done'));
    }
}
