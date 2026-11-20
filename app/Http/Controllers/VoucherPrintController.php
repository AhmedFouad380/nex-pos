<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Currency;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\ArabicAmountInWords;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Printable receipt / payment voucher (سند قبض / سند صرف) with the amount in words. */
class VoucherPrintController
{
    public function __invoke(Request $request, Payment $payment): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('payments.view'), 403);

        $payment->load(['contact', 'counterAccount', 'paymentMethod', 'account', 'location', 'creator', 'allocations.allocatable']);
        $settings = app(GeneralSettings::class);

        return view('finance.voucher', [
            'payment' => $payment,
            'settings' => $settings,
            'symbol' => Currency::query()->where('code', $settings->currency_code)->value('symbol') ?? $settings->currency_code,
            'logo' => $settings->logoDataUri(),
            'words' => ArabicAmountInWords::money($payment->amount, $settings->currency_code),
            'autoPrint' => $request->boolean('print', true),
        ]);
    }
}
