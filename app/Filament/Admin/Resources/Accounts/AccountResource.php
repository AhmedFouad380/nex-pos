<?php

namespace App\Filament\Admin\Resources\Accounts;

use App\Domain\Finance\Actions\SaveAccount;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Services\GeneralLedger;
use App\Filament\Admin\Resources\Accounts\Pages\AccountLedger;
use App\Filament\Admin\Resources\Accounts\Pages\ManageAccounts;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\DomainGuard;
use App\Support\Decimal;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Chart of accounts (شجرة الحسابات), shown as an indented tree with live balances. */
class AccountResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Account::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /** @var array<int, array{depth: int, closing: string}>|null */
    private static ?array $tree = null;

    public static function getModelLabel(): string
    {
        return __('Account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Chart of accounts');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'accounts.view' : 'accounts.manage';
    }

    /** Sub-types a user may give a new account (the rest belong to the system accounts). @return array<string, string> */
    public static function assignableSubTypes(): array
    {
        return collect([AccountSubType::Cash, AccountSubType::Bank, AccountSubType::Salaries, AccountSubType::OperatingExpense, AccountSubType::OtherIncome, AccountSubType::Other])
            ->mapWithKeys(fn (AccountSubType $t) => [$t->value => $t->getLabel()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('parent_id')->label(__('Parent account'))->options(AccountOptions::parents())->searchable()
                ->live()->afterStateUpdated(function (?string $state, Set $set) {
                    if ($state && $parent = Account::query()->find($state)) {
                        $set('type', $parent->type->value);
                    }
                })
                ->disabled(fn (?Account $record) => $record?->is_system),
            TextInput::make('code')->label(__('Code'))->required()->maxLength(30)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule),
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            Select::make('type')->label(__('Type'))->options(AccountType::class)->required()
                ->disabled(fn (?Account $record, Get $get) => $record?->is_system || filled($get('parent_id')))->dehydrated(),
            Select::make('sub_type')->label(__('Role'))
                ->options(fn (?Account $record) => $record?->is_system && $record->sub_type
                    ? [$record->sub_type->value => $record->sub_type->getLabel()]
                    : self::assignableSubTypes())
                ->disabled(fn (?Account $record) => $record?->is_system)
                ->helperText(__('Cash and bank accounts can receive payments.')),
            Toggle::make('is_postable')->label(__('Accepts entries'))->default(true)
                ->helperText(__('Turn off for a heading that only groups other accounts.'))
                ->disabled(fn (?Account $record) => $record?->is_system),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            TextInput::make('bank_name')->label(__('Bank name'))->maxLength(255)
                ->visible(fn (Get $get) => $get('sub_type') === AccountSubType::Bank->value || $get('sub_type') === AccountSubType::Bank),
            TextInput::make('account_number')->label(__('Account number'))->maxLength(60)
                ->visible(fn (Get $get) => $get('sub_type') === AccountSubType::Bank->value || $get('sub_type') === AccountSubType::Bank),
            Textarea::make('note')->label(__('Note'))->columnSpanFull(),
        ]);
    }

    /** @return array<int, array{depth: int, closing: string}> */
    public static function tree(): array
    {
        return self::$tree ??= app(GeneralLedger::class)->trialBalance()
            ->mapWithKeys(fn (array $row) => [$row['account']->id => ['depth' => $row['depth'], 'closing' => $row['closing']]])->all();
    }

    /** Balance in the account's natural direction (a positive liability means we owe it). */
    public static function naturalBalance(Account $account): float
    {
        $closing = Decimal::of(self::tree()[$account->id]['closing'] ?? '0');

        return ($account->type->isDebitNormal() ? $closing : $closing->negated())->toFloat();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->paginated([50, 100, 'all'])->defaultPaginationPageOption(100)
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label(__('Name'))->searchable()
                    ->formatStateUsing(fn (string $state, Account $record) => str_repeat('    ', self::tree()[$record->id]['depth'] ?? 0).$state)
                    ->extraAttributes(['style' => 'white-space: pre'])
                    ->weight(fn (Account $record) => $record->is_postable ? null : 'bold'),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('sub_type')->label(__('Role'))->placeholder('—')->toggleable(),
                TextColumn::make('balance')->label(__('Balance'))->state(fn (Account $record) => self::naturalBalance($record))
                    ->numeric(2)->color(fn ($state) => $state < 0 ? 'danger' : null)
                    ->weight(fn (Account $record) => $record->is_postable ? null : 'bold'),
                IconColumn::make('is_active')->label(__('Active'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(AccountType::class),
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                Action::make('ledger')->label(__('Ledger'))->icon('heroicon-o-book-open')->color('gray')
                    ->url(fn (Account $record) => self::getUrl('ledger', ['record' => $record])),
                EditAction::make()->using(fn (Account $record, array $data) => DomainGuard::run(fn () => app(SaveAccount::class)->handle($data, $record))),
                DeleteAction::make()->using(fn (Account $record) => DomainGuard::run(function () use ($record) {
                    app(SaveAccount::class)->delete($record);

                    return true;
                }))
                    ->visible(fn (Account $record) => ! $record->is_system && ! $record->children()->exists() && ! $record->journalLines()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
            'ledger' => AccountLedger::route('/{record}/ledger'),
        ];
    }
}
