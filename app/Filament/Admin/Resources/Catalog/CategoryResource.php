<?php

namespace App\Filament\Admin\Resources\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CategoryResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Category::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'catalog.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('code')->label(__('Code'))->maxLength(30),
            Select::make('parent_id')->label(__('Parent category'))
                ->relationship('parent', 'name', fn ($query, $record) => $query->when($record, fn ($q) => $q->whereKeyNot($record->id)))
                ->searchable()->preload(),
            TextInput::make('sort')->label(__('Sort order'))->numeric()->default(0),
            Textarea::make('description')->label(__('Description'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('parent.name')->label(__('Parent category'))->placeholder('—'),
                TextColumn::make('code')->label(__('Code'))->toggleable(),
                TextColumn::make('products_count')->label(__('Products'))->counts('products')->sortable(),
            ])
            ->filters([
                SelectFilter::make('parent_id')->label(__('Parent category'))->relationship('parent', 'name'),
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
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
