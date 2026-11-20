<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Admin sidebar groups, in display order.
 * Resources set `protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;`
 */
enum NavigationGroup implements HasLabel
{
    case Sales;
    case Purchasing;
    case Inventory;
    case Catalog;
    case Contacts;
    case Finance;
    case Payroll;
    case Wallets;
    case CashManagement;
    case Restaurant;
    case Reports;
    case Settings;

    public function getLabel(): string
    {
        return __('nav.'.str($this->name)->snake());
    }
}
