<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Resources\Settings\Pages\ManageLocations;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Location::class;

    protected static function permissionFor(string $action): string|false
    {
        return 'locations.manage';
    }

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Location');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Locations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('code')->label(__('Code'))->required()->maxLength(30)->unique(ignoreRecord: true),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(30),
            TextInput::make('email')->label(__('Email'))->email(),
            TextInput::make('address')->label(__('Address'))->columnSpanFull(),
            TextInput::make('city')->label(__('City')),
            TextInput::make('country')->label(__('Country')),
            Select::make('default_price_group_id')->label(__('Default price group'))->relationship('defaultPriceGroup', 'name'),
            Select::make('invoice_layout_id')->label(__('Invoice layout'))->relationship('invoiceLayout', 'name'),
            Select::make('receipt_layout_id')->label(__('Receipt layout'))->relationship('receiptLayout', 'name'),
            Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable(),
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('city')->label(__('City'))->placeholder('—'),
                TextColumn::make('phone')->label(__('Phone'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLocations::route('/')];
    }
}
