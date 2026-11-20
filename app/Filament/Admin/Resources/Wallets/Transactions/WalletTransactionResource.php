<?php

namespace App\Filament\Admin\Resources\Wallets\Transactions;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Filament\Admin\Resources\Wallets\Transactions\Pages\ListWalletTransactions;
use App\Filament\Admin\Resources\Wallets\Transactions\Pages\ViewWalletTransaction;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletTransactionResource extends Resource
{
    use AuthorizesWithPermissions;
    use HasNavigationCount;
    use ScopesToUserLocations;

    protected static ?string $model = WalletTransaction::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Wallets;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'wallet-transactions';

    public static function getModelLabel(): string
    {
        return __('Wallet transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Wallet transfers');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'wallet.view' : false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('amount')->label(__('Amount'))->numeric(2)->weight('bold')->size('lg')
                    ->color(fn (WalletTransaction $r) => $r->direction === PaymentDirection::In ? 'success' : 'warning'),
                TextEntry::make('direction')->label(__('Direction'))->badge(),
                TextEntry::make('provider')->label(__('Provider'))->badge()->placeholder('—'),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('counterparty_name')->label(__('Name'))->placeholder('—'),
                TextEntry::make('counterparty_phone')->label(__('Phone number'))->placeholder('—')->copyable(),
                TextEntry::make('reference')->label(__('Transaction number'))->placeholder('—')->copyable(),
                TextEntry::make('occurred_at')->label(__('Date'))->dateTime()->placeholder('—'),
                TextEntry::make('fee')->label(__('Service fee'))->numeric(2),
                TextEntry::make('balance')->label(__('Balance after'))->numeric(2)->placeholder('—'),
                TextEntry::make('wallet.name')->label(__('Wallet number'))->placeholder(__('Not known — choose it when confirming')),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('device.name')->label(__('Phone'))->placeholder('—'),
                TextEntry::make('payment.number')->label(__('Voucher'))->placeholder('—')
                    ->url(fn (WalletTransaction $r) => $r->payment_id ? route('vouchers.print', $r->payment_id) : null, true),
                TextEntry::make('confirmer.name')->label(fn (WalletTransaction $r) => $r->status === Status::Rejected ? __('Rejected by') : __('Confirmed by'))
                    ->placeholder('—')->helperText(fn (WalletTransaction $r) => $r->confirmed_at?->format('Y-m-d H:i')),
                TextEntry::make('reject_reason')->label(__('Reason'))->color('danger')->visible(fn (WalletTransaction $r) => $r->reject_reason !== null),
                TextEntry::make('note')->label(__('Note'))->placeholder('—'),
            ]),
            Section::make(__('Original message'))->columnSpanFull()->collapsible()->schema([
                TextEntry::make('sender')->label(__('Sender'))->placeholder('—'),
                TextEntry::make('body')->hiddenLabel()->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('template.name')->label(__('Read with template'))->placeholder(__('No template matched')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['wallet', 'location', 'payment']))
            ->defaultSort('id', 'desc')
            ->poll('20s')
            ->columns([
                TextColumn::make('occurred_at')->label(__('Date'))->dateTime('Y-m-d H:i')->sortable()
                    ->state(fn (WalletTransaction $r) => $r->occurred_at ?? $r->received_at),
                TextColumn::make('direction')->label(__('Direction'))->badge()->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2)->weight('bold')->sortable()
                    ->color(fn (WalletTransaction $r) => $r->direction === PaymentDirection::In ? 'success' : 'warning')
                    ->description(fn (WalletTransaction $r) => (float) $r->fee > 0 ? __('Fee :fee', ['fee' => number_format((float) $r->fee, 2)]) : null),
                TextColumn::make('counterparty_name')->label(__('From / to'))
                    ->state(fn (WalletTransaction $r) => $r->counterparty_name ?? $r->counterparty_phone)
                    ->description(fn (WalletTransaction $r) => $r->counterparty_name ? $r->counterparty_phone : null)
                    ->searchable(['counterparty_name', 'counterparty_phone', 'reference']),
                TextColumn::make('provider')->label(__('Provider'))->badge()->placeholder('—'),
                TextColumn::make('wallet.name')->label(__('Wallet number'))->placeholder('—')->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('payment.number')->label(__('Voucher'))->placeholder('—')
                    ->url(fn (WalletTransaction $r) => $r->payment_id ? route('vouchers.print', $r->payment_id) : null, true),
                TextColumn::make('location.name')->label(__('Location'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(Status::class)->default(Status::Pending->value),
                SelectFilter::make('direction')->label(__('Direction'))->options(PaymentDirection::class),
                SelectFilter::make('provider')->label(__('Provider'))->options(WalletProvider::class),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => self::userLocationOptions()),
            ])
            ->recordActions([
                WalletTransactionActions::confirm(),
                ActionGroup::make([ViewAction::make(), WalletTransactionActions::reject()]),
            ]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->where('status', Status::Pending);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Waiting for confirmation');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
            'view' => ViewWalletTransaction::route('/{record}'),
        ];
    }
}
