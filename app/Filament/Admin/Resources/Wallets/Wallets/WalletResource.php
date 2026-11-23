<?php

namespace App\Filament\Admin\Resources\Wallets\Wallets;

use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Models\Wallet;
use App\Filament\Admin\Resources\Wallets\Wallets\Pages\ManageWallets;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\UserLocations;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** The wallet numbers the business owns, each tied to the money account its balance lives in. */
class WalletResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Wallet::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Wallets;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'wallets';

    public static function getModelLabel(): string
    {
        return __('Wallet number');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Wallet numbers');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'wallet.view' : 'wallet.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('provider')->label(__('Provider'))->options(WalletProvider::class)->required(),
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->placeholder(__('e.g. Vodafone Cash — main branch')),
            TextInput::make('phone')->label(__('Phone number'))->tel()->maxLength(20)
                ->helperText(__('Messages that mention this number are matched to this wallet.')),
            Select::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options()),
            Select::make('sim_slot')->label(__('SIM on the branch phone'))->options([1 => 'SIM 1', 2 => 'SIM 2', 3 => 'SIM 3', 4 => 'SIM 4'])
                ->helperText(__('The branch has one phone; each of its lines is a wallet number.')),
            Select::make('account_id')->label(__('Account'))->options(fn () => AccountOptions::money())->searchable()
                ->helperText(__('Where its money is kept in the books. Empty = the account of the mobile wallet payment method.')),
            Section::make(__('Transfer limits'))->description(__('As the provider sets them for this line. Empty = no limit.'))
                ->columnSpanFull()->columns(4)->schema([
                    TextInput::make('daily_out_limit')->label(__('Daily sending limit'))->numeric()->minValue(0),
                    TextInput::make('monthly_out_limit')->label(__('Monthly sending limit'))->numeric()->minValue(0),
                    TextInput::make('daily_in_limit')->label(__('Daily receiving limit'))->numeric()->minValue(0),
                    TextInput::make('monthly_in_limit')->label(__('Monthly receiving limit'))->numeric()->minValue(0),
                ]),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['location', 'account']))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('bold'),
                TextColumn::make('provider')->label(__('Provider'))->badge(),
                TextColumn::make('phone')->label(__('Phone number'))->placeholder('—')->copyable(),
                TextColumn::make('location.name')->label(__('Location'))->placeholder('—')
                    ->description(fn (Wallet $r) => $r->sim_slot ? 'SIM '.$r->sim_slot : null),
                TextColumn::make('daily_out_limit')->label(__('Daily sending limit'))->numeric(2)->placeholder('—')->toggleable(),
                TextColumn::make('monthly_out_limit')->label(__('Monthly sending limit'))->numeric(2)->placeholder('—')->toggleable(),
                TextColumn::make('account.name')->label(__('Account'))->placeholder(__('Default')),
                TextColumn::make('last_balance')->label(__('Balance on the phone'))->numeric(2)->placeholder('—')
                    ->description(fn (Wallet $r) => $r->last_balance_at?->diffForHumans()),
                TextColumn::make('books_balance')->label(__('Balance in the books'))->numeric(2)
                    ->state(fn (Wallet $r) => $r->account_id
                        ? (float) DB::table('journal_lines')->where('account_id', $r->account_id)->sum(DB::raw('debit - credit')) : null)
                    ->placeholder('—')
                    ->color(fn (Wallet $r, $state) => $state !== null && $r->last_balance !== null && abs((float) $state - (float) $r->last_balance) > 0.009 ? 'danger' : null)
                    ->tooltip(__('Red = differs from what the phone reports: a transfer is still unconfirmed or was recorded by hand.')),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWallets::route('/')];
    }
}
