<?php

namespace App\Filament\Admin\Resources\StockAdjustments;

use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Filament\Admin\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Admin\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Admin\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Components\StockLinesRepeater;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockAdjustmentResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;

    protected static ?string $model = StockAdjustment::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Stock adjustment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock adjustments');
    }

    /** Stock documents are immutable: they are voided, never edited or deleted. */
    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'stock_adjustments.view',
            'create' => 'stock_adjustments.create',
            default => false,
        };
    }

    public static function typeOptions(): array
    {
        $types = StockAdjustmentType::options();
        if (! auth()->user()?->can('opening_stock.manage')) {
            unset($types[StockAdjustmentType::Opening->value]);
        }

        return $types;
    }

    public static function isIncoming(Get $get): bool
    {
        return in_array($get('type'), [StockAdjustmentType::Opening->value, StockAdjustmentType::Increase->value, StockAdjustmentType::Opening, StockAdjustmentType::Increase], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions())
                ->default(fn () => array_key_first(static::userLocationOptions()))->required(),
            Select::make('type')->label(__('Adjustment type'))->options(fn () => static::typeOptions())->required()->live()
                ->default(StockAdjustmentType::Decrease->value),
            DateTimePicker::make('adjusted_at')->label(__('Date'))->default(now())->required()->maxDate(now()->addDay()),
            TextInput::make('reason')->label(__('Reason'))->maxLength(255)->columnSpan(2),
            TextInput::make('recovered_amount')->label(__('Amount recovered (e.g. insurance)'))->numeric()->minValue(0)
                ->visible(fn (Get $get) => ! static::isIncoming($get)),
            StockLinesRepeater::make('items', withCost: fn (Get $get) => static::isIncoming($get)),
            Textarea::make('note')->label(__('Note'))->columnSpanFull()->rows(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('type')->label(__('Adjustment type'))->badge(),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('adjusted_at')->label(__('Date'))->dateTime(),
                TextEntry::make('reason')->label(__('Reason'))->placeholder('—'),
                TextEntry::make('total_cost')->label(__('Total cost'))->numeric(2),
                TextEntry::make('recovered_amount')->label(__('Amount recovered (e.g. insurance)'))->numeric(2),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')
                    ->visible(fn (StockAdjustment $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')
                    ->visible(fn (StockAdjustment $r) => $r->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Product')), TableColumn::make(__('Unit')), TableColumn::make(__('Quantity')),
                        TableColumn::make(__('Unit cost')), TableColumn::make(__('Total')), TableColumn::make(__('Lot')),
                    ])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('unit.name'),
                        TextEntry::make('qty')->numeric(2)->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                        TextEntry::make('unit_cost')->numeric(2),
                        TextEntry::make('line_total')->numeric(2),
                        TextEntry::make('lot.number')->placeholder('—'),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['location', 'creator']))
            ->defaultSort('adjusted_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold'),
                TextColumn::make('adjusted_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('type')->label(__('Adjustment type'))->badge(),
                TextColumn::make('location.name')->label(__('Location')),
                TextColumn::make('total_cost')->label(__('Total cost'))->numeric(2)->sortable(),
                TextColumn::make('reason')->label(__('Reason'))->limit(40)->toggleable(),
                TextColumn::make('voided_at')->label(__('Status'))
                    ->state(fn (StockAdjustment $r) => $r->voided_at ? __('Voided') : __('Posted'))
                    ->badge()->color(fn (StockAdjustment $r) => $r->voided_at ? 'danger' : 'success'),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Adjustment type'))->options(StockAdjustmentType::class),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions()),
                TernaryFilter::make('voided')->label(__('Voided'))->nullable()->attribute('voided_at'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockAdjustments::route('/'),
            'create' => CreateStockAdjustment::route('/create'),
            'view' => ViewStockAdjustment::route('/{record}'),
        ];
    }
}
