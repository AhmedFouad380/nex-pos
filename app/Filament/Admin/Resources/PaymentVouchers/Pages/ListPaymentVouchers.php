<?php

namespace App\Filament\Admin\Resources\PaymentVouchers\Pages;

use App\Filament\Admin\Resources\PaymentVouchers\PaymentVoucherResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Resources\Pages\ListRecords;

class ListPaymentVouchers extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = PaymentVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [PaymentActions::voucher(PaymentVoucherResource::direction())];
    }
}
