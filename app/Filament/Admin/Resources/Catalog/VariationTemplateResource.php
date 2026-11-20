<?php

namespace App\Filament\Admin\Resources\Catalog;

use App\Domain\Catalog\Models\VariationTemplate;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariationTemplateResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = VariationTemplate::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'catalog.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Variation template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Variation templates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->helperText(__('Example: Size, Color')),
            Repeater::make('values')->label(__('Values'))->relationship()->orderColumn('sort')
                ->simple(TextInput::make('value')->required()->maxLength(255))
                ->columnSpanFull()->minItems(1)->reorderable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('values.value')->label(__('Values'))->badge()->limitList(8),
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
            'index' => Pages\ManageVariationTemplates::route('/'),
        ];
    }
}
