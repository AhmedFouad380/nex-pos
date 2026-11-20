<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Printing\Enums\PaperSize;
use App\Domain\Printing\Models\InvoiceLayout;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        if (Location::query()->exists()) {
            return; // a live install already has its branches
        }

        $location = Location::query()->create([
            'code' => 'MAIN',
            'name' => 'الفرع الرئيسي',
            'city' => 'القاهرة',
            'country' => 'مصر',
            'is_active' => true,
            'invoice_layout_id' => InvoiceLayout::query()->where('paper', PaperSize::A4)->value('id'),
            'receipt_layout_id' => InvoiceLayout::query()->where('paper', PaperSize::Thermal80)->value('id'),
        ]);

        $location->paymentMethods()->sync(
            PaymentMethod::query()->get()->mapWithKeys(fn (PaymentMethod $m) => [
                $m->id => ['account_id' => $m->account_id, 'is_enabled' => true],
            ])->all()
        );
    }
}
