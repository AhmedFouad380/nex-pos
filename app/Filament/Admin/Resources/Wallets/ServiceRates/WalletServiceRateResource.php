<?php

namespace App\Filament\Admin\Resources\Wallets\ServiceRates;

use App\Domain\Wallet\Models\WalletServiceRate;
use App\Filament\Admin\Resources\Wallets\ServiceRates\Pages\ManageWalletServiceRates;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\UserLocations;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Each branch's commission on the wallet service: a percentage with a minimum, for transfers and for withdrawals. */
class WalletServiceRateResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = WalletServiceRate::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Wallets;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'wallet-service-rates';

    public static function getModelLabel(): string
    {
        return __('Branch commission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Wallet service commissions');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'wallet.view' : 'wallet.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options())->required()
                ->unique(ignoreRecord: true)->columnSpanFull(),
            Section::make(__('Transfer for a customer'))->description(__('The customer pays cash and the branch sends it from its wallet.'))
                ->columns(2)->schema([
                    TextInput::make('transfer_percent')->label(__('Percentage'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required()->default(0),
                    TextInput::make('transfer_min')->label(__('Minimum'))->numeric()->minValue(0)->required()->default(0),
                ]),
            Section::make(__('Withdrawal for a customer'))->description(__('The customer sends to the branch wallet and takes cash.'))
                ->columns(2)->schema([
                    TextInput::make('withdraw_percent')->label(__('Percentage'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required()->default(0),
                    TextInput::make('withdraw_min')->label(__('Minimum'))->numeric()->minValue(0)->required()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->columns([
                TextColumn::make('location.name')->label(__('Location'))->weight('bold'),
                TextColumn::make('transfer_percent')->label(__('Transfer %'))->numeric(2)->suffix('%'),
                TextColumn::make('transfer_min')->label(__('Transfer minimum'))->numeric(2),
                TextColumn::make('withdraw_percent')->label(__('Withdrawal %'))->numeric(2)->suffix('%'),
                TextColumn::make('withdraw_min')->label(__('Withdrawal minimum'))->numeric(2),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWalletServiceRates::route('/')];
    }
}
