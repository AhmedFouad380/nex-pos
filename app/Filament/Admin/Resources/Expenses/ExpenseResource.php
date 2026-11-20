<?php

namespace App\Filament\Admin\Resources\Expenses;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpenseResource extends Resource
{
    use AuthorizesWithPermissions;
    use HasNavigationCount;
    use ScopesToUserLocations;

    protected static ?string $model = Expense::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expenses');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'expenses.view',
            'create' => 'expenses.create',
            default => false, // expenses are voided, never edited
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                Select::make('location_id')->label(__('Location'))->options(fn () => self::userLocationOptions())
                    ->default(fn () => array_key_first(self::userLocationOptions()))->required(),
                Select::make('expense_category_id')->label(__('Expense category'))
                    ->options(fn () => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                DateTimePicker::make('expense_date')->label(__('Date'))->default(now())->required(),
                TextInput::make('amount')->label(__('Amount before tax'))->numeric()->minValue(0.01)->required()->live(onBlur: true),
                Select::make('tax_rate_id')->label(__('Tax'))->options(fn () => TaxRate::query()->pluck('name', 'id'))->live(),
                TextInput::make('ref_no')->label(__('Reference'))->maxLength(60),
                Textarea::make('note')->label(__('Note'))->rows(2)->columnSpan(2),
            ]),
            Section::make(__('Payment'))->columnSpanFull()->columns(3)->schema([
                Toggle::make('pay_now')->label(__('Paid now'))->default(true)->live()->columnSpanFull(),
                Select::make('payment_method_id')->label(__('Payment method'))
                    ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->orderBy('sort')->pluck('name', 'id'))
                    ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id'))
                    ->required(fn (Get $get) => (bool) $get('pay_now'))->visible(fn (Get $get) => (bool) $get('pay_now')),
                TextInput::make('paid_amount')->label(__('Amount paid'))->numeric()->minValue(0.01)
                    ->placeholder(__('Full amount'))->visible(fn (Get $get) => (bool) $get('pay_now')),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('expense_date')->label(__('Date'))->dateTime(),
                TextEntry::make('expenseCategory.name')->label(__('Expense category'))->placeholder('—'),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('subtotal')->label(__('Amount before tax'))->numeric(2),
                TextEntry::make('tax_amount')->label(__('Tax'))->numeric(2),
                TextEntry::make('total')->label(__('Total'))->numeric(2)->weight('bold')->size('lg'),
                TextEntry::make('payment_status')->label(__('Payment status'))->badge(),
                TextEntry::make('due_total')->label(__('Due'))->numeric(2)->color(fn ($state) => (float) $state > 0 ? 'danger' : null),
                TextEntry::make('ref_no')->label(__('Reference'))->placeholder('—'),
                // older expenses only: the field was removed (salaries and advances have their own screens)
                TextEntry::make('expenseForUser.name')->label(__('Expense for'))->visible(fn (Expense $r) => $r->expense_for_user_id !== null),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn (Expense $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')->visible(fn (Expense $r) => $r->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Payments'))->columnSpanFull()->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()
                    ->state(fn (Expense $record) => $record->allocations()->with('payment')->get())
                    ->table([TableColumn::make(__('Number')), TableColumn::make(__('Date')), TableColumn::make(__('Amount'))])
                    ->schema([
                        TextEntry::make('payment.number')->url(fn ($record) => route('vouchers.print', $record->payment_id), true)
                            ->color(fn ($record) => $record->payment->voided_at ? 'danger' : 'primary'),
                        TextEntry::make('payment.paid_at')->dateTime(),
                        TextEntry::make('amount')->numeric(2),
                    ])
                    ->placeholder(__('Not paid yet.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['expenseCategory', 'location']))
            ->defaultSort('expense_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (Expense $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('expense_date')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('expenseCategory.name')->label(__('Expense category'))->placeholder('—'),
                TextColumn::make('location.name')->label(__('Location'))->toggleable(),
                TextColumn::make('total')->label(__('Total'))->numeric(2)->sortable()->summarize(Sum::make()->numeric(2)),
                TextColumn::make('payment_status')->label(__('Payment status'))->badge(),
                TextColumn::make('note')->label(__('Note'))->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('expense_category_id')->label(__('Expense category'))->relationship('expenseCategory', 'name'),
                SelectFilter::make('payment_status')->label(__('Payment status'))->options(PaymentStatus::class),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('to')->label(__('To')),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
                    ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d))),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->where('due_total', '>', 0);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Unpaid expenses');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
        ];
    }
}
