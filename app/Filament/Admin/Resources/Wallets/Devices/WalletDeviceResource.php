<?php

namespace App\Filament\Admin\Resources\Wallets\Devices;

use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Services\DevicePairing;
use App\Filament\Admin\Resources\Wallets\Devices\Pages\ManageWalletDevices;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use App\Filament\Support\UserLocations;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Phones running the wallet app. New ones are paired with a QR code (see ManageWalletDevices). */
class WalletDeviceResource extends Resource
{
    use AuthorizesWithPermissions;
    use ScopesToUserLocations;

    protected static ?string $model = WalletDevice::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Wallets;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'wallet-devices';

    public static function getModelLabel(): string
    {
        return __('Phone');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Wallet phones');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'wallet.view' : 'wallet.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            Select::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options())->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['location', 'pairer'])->withCount(['transactions as pending_count' => fn ($q) => $q->where('status', 'pending')]))
            ->poll('30s')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable()
                    ->description(fn (WalletDevice $r) => $r->revoked_at ? __('Unlinked') : null),
                IconColumn::make('online')->label(__('Online'))->boolean()->state(fn (WalletDevice $r) => $r->isOnline()),
                TextColumn::make('last_seen_at')->label(__('Last seen'))->since()->placeholder('—'),
                TextColumn::make('location.name')->label(__('Location')),
                TextColumn::make('pending_count')->label(__('Waiting for confirmation'))->numeric(),
                TextColumn::make('app_version')->label(__('App version'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pairer.name')->label(__('Linked by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Linked on'))->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('hide_revoked')->label(__('Hide unlinked'))->default()->query(fn (Builder $query) => $query->whereNull('revoked_at')),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (WalletDevice $r) => $r->revoked_at === null),
                Action::make('revoke')->label(__('Unlink'))->icon('heroicon-o-link-slash')->color('danger')->requiresConfirmation()
                    ->modalDescription(__('The app on this phone stops working until it is paired again.'))
                    ->visible(fn (WalletDevice $r) => $r->revoked_at === null && auth()->user()?->can('wallet.manage'))
                    ->action(function (WalletDevice $record) {
                        app(DevicePairing::class)->revoke($record);
                        Notification::make()->success()->title(__('Unlinked'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWalletDevices::route('/')];
    }
}
