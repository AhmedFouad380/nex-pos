<?php

namespace App\Filament\Admin\Resources\Customers;

use App\Domain\CRM\Enums\ContactType;
use App\Filament\Admin\Support\ContactResource;
use Filament\Support\Icons\Heroicon;

class CustomerResource extends ContactResource
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 1;

    public static function side(): ContactType
    {
        return ContactType::Customer;
    }

    public static function getModelLabel(): string
    {
        return __('Customer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customers');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
            'statement' => Pages\CustomerStatement::route('/{record}/statement'),
        ];
    }
}
