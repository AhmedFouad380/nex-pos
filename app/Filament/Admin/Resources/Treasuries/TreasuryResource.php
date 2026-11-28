<?php

namespace App\Filament\Admin\Resources\Treasuries;

use App\Domain\CashManagement\Actions\AdjustBoxToCount;
use App\Domain\CashManagement\Services\BranchCash;
use App\Domain\Finance\Actions\SaveAccount;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Models\Account;
use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Resources\Treasuries\Pages\ManageTreasuries;
use App\Filament\Admin\Resources\Treasuries\Pages\TreasuryStatement;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\DomainGuard;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الخزن: the cash boxes and banks (accounts with the cash / bank role), their balance and the branches whose
 * drawer posts to them; each opens on its statement. A new box can be made the cash box of a branch.
 */
class TreasuryResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Account::class;

    protected static ?string $slug = 'treasuries';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CashManagement;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    /** @var array<int, list<int>>|null */
    private static ?array $branches = null;

    public static function getModelLabel(): string
    {
        return __('Cash box');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cash boxes');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'accounts.view',
            'create', 'update' => 'accounts.manage',
            default => false,
        };
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_postable', true)->whereIn('sub_type', [AccountSubType::Cash, AccountSubType::Bank])
            ->addSelect(['balance' => DB::table('journal_lines')->whereColumn('journal_lines.account_id', 'accounts.id')
                ->selectRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0)')]);
    }

    /** @return list<int> branches whose drawer posts to the box */
    public static function branchesOf(Account $account): array
    {
        return (self::$branches ??= app(BranchCash::class)->branchesByBox())[$account->id] ?? [];
    }

    /** @return array<int, string> branches, those with a shift open marked (their box cannot change now) */
    public static function branchOptions(): array
    {
        $cash = app(BranchCash::class);

        return Location::query()->orderBy('name')->pluck('name', 'id')
            ->map(fn (string $name, int $id) => $cash->openShift($id) ? $name.' — '.__('shift open, close it first') : $name)->all();
    }

    public static function forgetBranches(): void
    {
        self::$branches = null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            Select::make('sub_type')->label(__('Type'))->required()->live()->default(AccountSubType::Cash->value)
                ->options([AccountSubType::Cash->value => AccountSubType::Cash->getLabel(), AccountSubType::Bank->value => AccountSubType::Bank->getLabel()])
                ->disabledOn('edit'),
            Select::make('location_id')->label(__('Cash box of branch'))->options(fn () => self::branchOptions())
                ->disableOptionWhen(fn (string $value) => app(BranchCash::class)->openShift((int) $value) !== null)
                ->helperText(__('The branch drawer posts its cash here from now on. Leave empty for a box of its own (e.g. the main safe).'))
                ->visibleOn('create'),
            TextInput::make('bank_name')->label(__('Bank name'))->maxLength(255)
                ->visible(fn (Get $get) => in_array($get('sub_type'), [AccountSubType::Bank->value, AccountSubType::Bank], true)),
            TextInput::make('account_number')->label(__('Account number'))->maxLength(60)
                ->visible(fn (Get $get) => in_array($get('sub_type'), [AccountSubType::Bank->value, AccountSubType::Bank], true)),
            Toggle::make('is_active')->label(__('Active'))->default(true)->visibleOn('edit'),
            Textarea::make('note')->label(__('Note'))->columnSpanFull(),
        ]);
    }

    /** Makes the account under the heading the other boxes of its kind sit under, with the next code there. */
    public static function createBox(array $data): Account
    {
        $subType = AccountSubType::from($data['sub_type']);

        return DB::transaction(function () use ($data, $subType) {
            $sibling = Account::query()->where('sub_type', $subType)->where('is_postable', true)->whereNotNull('parent_id')
                ->select('parent_id')->groupBy('parent_id')->orderByRaw('count(*) desc')->value('parent_id');
            $parent = $sibling ? Account::query()->find($sibling) : null;
            $codes = Account::query()->where('parent_id', $parent?->id)->pluck('code')->filter(fn ($c) => ctype_digit((string) $c));
            $code = $codes->isNotEmpty() ? (string) ($codes->map(fn ($c) => (int) $c)->max() + 1) : ($parent ? $parent->code.'0001' : 'BOX-'.now()->format('ymdHis'));

            $account = app(SaveAccount::class)->handle([
                'parent_id' => $parent?->id, 'code' => $code, 'name' => $data['name'], 'type' => AccountType::Asset,
                'sub_type' => $subType, 'is_postable' => true, 'is_active' => true,
                'bank_name' => $data['bank_name'] ?? null, 'account_number' => $data['account_number'] ?? null, 'note' => $data['note'] ?? null,
            ]);
            if (filled($data['location_id'] ?? null)) {
                app(BranchCash::class)->assign($account, (int) $data['location_id']);
            }
            self::forgetBranches();

            return $account;
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('bold')
                    ->description(fn (Account $record) => $record->bank_name),
                TextColumn::make('sub_type')->label(__('Type'))->badge(),
                TextColumn::make('branches')->label(__('Cash box of branch'))->placeholder('—')
                    ->state(fn (Account $record) => Location::query()->whereKey(self::branchesOf($record))->pluck('name')->implode('، ') ?: null),
                TextColumn::make('balance')->label(__('Balance'))->numeric(2)->sortable()->weight('bold')
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
                IconColumn::make('is_active')->label(__('Active'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('branch_boxes')->label(__('Branch cash boxes only'))
                    ->query(fn (Builder $query) => $query->whereKey(array_keys(app(BranchCash::class)->branchesByBox()))),
                SelectFilter::make('sub_type')->label(__('Type'))
                    ->options([AccountSubType::Cash->value => AccountSubType::Cash->getLabel(), AccountSubType::Bank->value => AccountSubType::Bank->getLabel()]),
                Filter::make('active')->label(__('Active only'))->default()->query(fn (Builder $query) => $query->where('is_active', true)),
                Filter::make('with_balance')->label(__('With a balance only'))
                    ->query(fn (Builder $query) => $query->whereRaw('(select coalesce(sum(debit), 0) - coalesce(sum(credit), 0) from journal_lines where journal_lines.account_id = accounts.id) <> 0')),
            ])
            ->recordUrl(fn (Account $record) => self::getUrl('statement', ['record' => $record]))
            ->recordActions([
                Action::make('statement')->label(__('Cash box statement'))->icon('heroicon-o-book-open')->color('gray')
                    ->url(fn (Account $record) => self::getUrl('statement', ['record' => $record])),
                Action::make('assignBranch')->label(__('Make it a branch box'))->icon('heroicon-o-building-storefront')->color('gray')
                    ->visible(fn () => (bool) auth()->user()?->can('accounts.manage'))
                    ->modalDescription(__('The branch drawer posts its cash to this box from now on; what is already on its old box stays there and can be moved with an account transfer.'))
                    ->schema([Select::make('location_id')->label(__('Location'))->required()->options(fn () => self::branchOptions())
                        ->disableOptionWhen(fn (string $value) => app(BranchCash::class)->openShift((int) $value) !== null)])
                    ->action(function (Account $record, array $data) {
                        DomainGuard::run(fn () => app(BranchCash::class)->assign($record, (int) $data['location_id']));
                        self::forgetBranches();
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                Action::make('adjustToCount')->label(__('Adjust to count'))->icon('heroicon-o-calculator')->color('warning')
                    ->visible(fn () => (bool) auth()->user()?->can('accounts.manage'))
                    ->modalDescription(__('Enter the cash actually counted in the box. An entry brings the book balance to the count; the difference goes to the account you choose.'))
                    ->schema([
                        TextInput::make('counted')->label(__('Counted cash'))->numeric()->minValue(0)->required()
                            ->helperText(fn (Account $record) => __('Book balance').': '.number_format((float) $record->getAttribute('balance'), 2)),
                        Select::make('counter_account_id')->label(__('Difference goes to'))->options(fn () => AccountOptions::manual())->searchable()->required(),
                        DatePicker::make('date')->label(__('Date'))->default(now())->required(),
                        Textarea::make('note')->label(__('Note'))->rows(2),
                    ])
                    ->action(function (Account $record, array $data) {
                        $entry = DomainGuard::run(fn () => app(AdjustBoxToCount::class)->handle($record, $data['counted'], (int) $data['counter_account_id'],
                            Carbon::parse($data['date']), $data['note'] ?? null));
                        Notification::make()->success()->title($entry ? __('Box adjusted to the count') : __('The box already matches the count'))->send();
                    }),
                EditAction::make()->using(fn (Account $record, array $data) => DomainGuard::run(fn () => app(SaveAccount::class)->handle([
                    'parent_id' => $record->parent_id, 'code' => $record->code, 'type' => $record->type, 'sub_type' => $record->sub_type,
                    'is_postable' => true, ...$data,
                ], $record))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTreasuries::route('/'),
            'statement' => TreasuryStatement::route('/{record}/statement'),
        ];
    }
}
