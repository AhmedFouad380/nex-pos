<?php

namespace App\Filament\Admin\Resources\ReceiptVouchers;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Filament\Admin\Resources\ReceiptVouchers\Pages\ListReceiptVouchers;
use App\Filament\Admin\Resources\ReceiptVouchers\Pages\ViewReceiptVoucher;
use App\Filament\Admin\Support\VoucherResource;
use Filament\Support\Icons\Heroicon;

class ReceiptVoucherResource extends VoucherResource
{
    protected static ?string $slug = 'receipt-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 10;

    public static function direction(): PaymentDirection
    {
        return PaymentDirection::In;
    }

    public static function getModelLabel(): string
    {
        return __('Receipt voucher');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Receipt vouchers');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceiptVouchers::route('/'),
            'view' => ViewReceiptVoucher::route('/{record}'),
        ];
    }
}
