<?php

namespace App\Filament\Admin\Resources\Catalog;

use App\Domain\Catalog\Models\Warranty;
use App\Domain\Shared\Enums\DurationUnit;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarrantyResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Warranty::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'catalog.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Warranty');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Warranties');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('duration')->label(__('Duration'))->numeric()->integer()->minValue(1)->required(),
            Select::make('duration_unit')->label(__('Unit'))->options(DurationUnit::class)->default(DurationUnit::Month)->required(),
            Textarea::make('description')->label(__('Description'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('duration')->label(__('Duration'))
                    ->formatStateUsing(fn ($state, $record) => $state.' '.$record->duration_unit->getLabel()),
            ])
            ->filters([
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWarranties::route('/'),
        ];
    }
}
