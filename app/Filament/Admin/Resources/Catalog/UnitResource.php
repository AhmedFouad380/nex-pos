<?php

namespace App\Filament\Admin\Resources\Catalog;

use App\Domain\Catalog\Models\Unit;
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

class UnitResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Unit::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'catalog.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Unit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Units');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('short_name')->label(__('Short name'))->required()->maxLength(20),
            Toggle::make('allow_decimal')->label(__('Allow decimal quantities')),
            Select::make('base_unit_id')->label(__('Multiple of unit'))
                ->helperText(__('Leave empty for a base unit. Example: carton = 12 × piece.'))
                ->relationship('baseUnit', 'name', fn ($query, $record) => $query->whereNull('base_unit_id')->when($record, fn ($q) => $q->whereKeyNot($record->id)))
                ->live(),
            TextInput::make('base_multiplier')->label(__('Contains'))->numeric()->minValue(0.0001)->default(1)
                ->suffix(fn (Get $get) => $get('base_unit_id') ? Unit::query()->find($get('base_unit_id'))?->name : null)
                ->visible(fn (Get $get) => filled($get('base_unit_id')))->required(fn (Get $get) => filled($get('base_unit_id'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('short_name')->label(__('Short name')),
                TextColumn::make('baseUnit.name')->label(__('Multiple of unit'))
                    ->formatStateUsing(fn ($state, $record) => (float) $record->base_multiplier.' × '.$state)->placeholder('—'),
                IconColumn::make('allow_decimal')->label(__('Decimal'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUnits::route('/'),
        ];
    }
}
