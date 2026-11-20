<?php

use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Services\DocumentCalculator;

beforeEach(fn () => seedBaseData());

it('applies line discount, line tax, invoice discount, invoice tax and charges', function () {
    $t = app(DocumentCalculator::class)->calculate([
        new LineInput('2', '100', DiscountType::Percent, '10', '14'), // 90 → +12.6 tax → 102.6 × 2 = 205.2
        new LineInput('1', '50', DiscountType::Fixed, '5'),           // 45
    ], DiscountType::Fixed, '10.2', invoiceTaxRate: '0', charges: '20');

    expect((string) $t->lines[0]->unitPrice)->toBe('90.0000')
        ->and((string) $t->lines[0]->unitPriceIncTax)->toBe('102.6000')
        ->and((string) $t->lines[0]->lineTotal)->toBe('205.2000')
        ->and((string) $t->subtotal)->toBe('250.2000')
        ->and((string) $t->taxAmount)->toBe('25.2000')
        ->and((string) $t->total)->toBe('260.0000'); // 250.2 − 10.2 + 20
});

it('taxes the discounted subtotal at invoice level and caps discounts', function () {
    $t = app(DocumentCalculator::class)->calculate([new LineInput('1', '100')], DiscountType::Percent, '150', invoiceTaxRate: '14');

    expect((string) $t->discountAmount)->toBe('100.0000')->and((string) $t->total)->toBe('0.0000');

    $t = app(DocumentCalculator::class)->calculate([new LineInput('3', '33.333')], DiscountType::Percent, '10', invoiceTaxRate: '14');
    expect((string) $t->subtotal)->toBe('100.0000')   // 99.999 rounded to 2 decimals
        ->and((string) $t->invoiceTax)->toBe('12.6000') // (100 − 10) × 14%
        ->and((string) $t->total)->toBe('102.6000');
});
