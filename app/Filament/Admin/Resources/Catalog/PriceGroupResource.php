<?php

namespace App\Filament\Admin\Resources\Catalog;

use App\Domain\Catalog\Models\PriceGroup;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceGroupResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = PriceGroup::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'catalog.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Price group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Price groups');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->unique(ignoreRecord: true),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            Textarea::make('description')->label(__('Description'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('description')->label(__('Description'))->limit(60),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
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
            'index' => Pages\ManagePriceGroups::route('/'),
        ];
    }
}
