<?php

namespace App\Filament\Admin\Resources\ReceiptVouchers\Pages;

use App\Filament\Admin\Resources\ReceiptVouchers\ReceiptVoucherResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Resources\Pages\ListRecords;

class ListReceiptVouchers extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = ReceiptVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [PaymentActions::voucher(ReceiptVoucherResource::direction())];
    }
}
