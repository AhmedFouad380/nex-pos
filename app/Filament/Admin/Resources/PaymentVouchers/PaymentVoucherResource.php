<?php

namespace App\Filament\Admin\Resources\PaymentVouchers;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ListPaymentVouchers;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ViewPaymentVoucher;
use App\Filament\Admin\Support\VoucherResource;
use Filament\Support\Icons\Heroicon;

class PaymentVoucherResource extends VoucherResource
{
    protected static ?string $slug = 'payment-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 11;

    public static function direction(): PaymentDirection
    {
        return PaymentDirection::Out;
    }

    public static function getModelLabel(): string
    {
        return __('Payment voucher');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payment vouchers');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentVouchers::route('/'),
            'view' => ViewPaymentVoucher::route('/{record}'),
        ];
    }
}
