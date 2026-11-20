<?php

namespace App\Filament\Admin\Resources\Catalog;

use App\Domain\Catalog\Models\TaxRate;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TaxRateResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = TaxRate::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'catalog.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Tax rate');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Tax rates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            Toggle::make('is_group')->label(__('Tax group (sum of several rates)'))->live(),
            TextInput::make('rate')->label(__('Rate'))->numeric()->suffix('%')->minValue(0)->maxValue(100)
                ->required(fn (Get $get) => ! $get('is_group'))->hidden(fn (Get $get) => $get('is_group')),
            Select::make('members')->label(__('Rates in this group'))->multiple()
                ->relationship('members', 'name', fn ($query) => $query->where('is_group', false))
                ->visible(fn (Get $get) => $get('is_group'))->required(fn (Get $get) => $get('is_group')),
            Toggle::make('for_group_only')->label(__('Only usable inside a tax group'))->hidden(fn (Get $get) => $get('is_group')),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('rate')->label(__('Rate'))->suffix('%')->numeric(2),
                IconColumn::make('is_group')->label(__('Group'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->after(fn ($record) => TaxRateResource::syncGroupRate($record)),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** A group's rate is always the sum of its members. */
    public static function syncGroupRate(TaxRate $rate): void
    {
        if ($rate->is_group) {
            $rate->update(['rate' => $rate->members()->sum('rate')]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTaxRates::route('/'),
        ];
    }
}
