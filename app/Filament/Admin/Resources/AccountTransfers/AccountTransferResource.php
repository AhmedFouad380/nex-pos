<?php

namespace App\Filament\Admin\Resources\AccountTransfers;

use App\Domain\Finance\Actions\TransferBetweenAccounts;
use App\Domain\Finance\Models\AccountTransfer;
use App\Filament\Admin\Resources\AccountTransfers\Pages\ManageAccountTransfers;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\DomainGuard;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Moving money between cash boxes and banks (deposit the day's cash, withdraw, move between safes). */
class AccountTransferResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = AccountTransfer::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('Account transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Account transfers');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view', 'create'], true) ? 'account_transfers.manage' : false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('from_account_id')->label(__('From'))->options(fn () => AccountOptions::money())->searchable()->required(),
            Select::make('to_account_id')->label(__('To'))->options(fn () => AccountOptions::money())->searchable()->required()->different('from_account_id'),
            TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required(),
            DateTimePicker::make('transferred_at')->label(__('Date'))->default(now())->required(),
            Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromAccount', 'toAccount', 'creator']))
            ->defaultSort('transferred_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (AccountTransfer $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('transferred_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('fromAccount.name')->label(__('From')),
                TextColumn::make('toAccount.name')->label(__('To')),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2),
                TextColumn::make('note')->label(__('Note'))->limit(40)->toggleable(),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([
                Action::make('void')->label(__('Void'))->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (AccountTransfer $record) => $record->voided_at === null)
                    ->requiresConfirmation()
                    ->action(function (AccountTransfer $record) {
                        DomainGuard::run(fn () => app(TransferBetweenAccounts::class)->void($record));
                        Notification::make()->success()->title(__('Voided'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAccountTransfers::route('/')];
    }
}
