<?php

namespace App\Filament\Admin\Resources\Suppliers;

use App\Domain\CRM\Enums\ContactType;
use App\Filament\Admin\Support\ContactResource;
use Filament\Support\Icons\Heroicon;

class SupplierResource extends ContactResource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 2;

    public static function side(): ContactType
    {
        return ContactType::Supplier;
    }

    public static function getModelLabel(): string
    {
        return __('Supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Suppliers');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view' => Pages\ViewSupplier::route('/{record}'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
            'statement' => Pages\SupplierStatement::route('/{record}/statement'),
        ];
    }
}
