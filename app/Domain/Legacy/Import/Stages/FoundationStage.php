<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Domain\Printing\Enums\PaperSize;
use App\Domain\Printing\Models\InvoiceLayout;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Business details, branches (each old branch has one store → one location, with its own cash box) and the
 * POS terminals (→ cash drawers). Runs after AccountsStage so cash boxes exist.
 */
final class FoundationStage implements Stage
{
    public function name(): string
    {
        return 'foundation';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        DB::transaction(function () use ($package, $context) {
            $this->company($package, $context);
            $this->locations($package, $context);
            $this->registers($package, $context);
        });
    }

    private function company(LegacyPackage $package, ImportContext $context): void
    {
        $company = $package->all('Company')[0] ?? null;
        if (! $company) {
            return;
        }
        $settings = app(GeneralSettings::class);
        $name = trim((string) ($company['NameA'] ?: $company['NameE']));
        if ($name !== '' && in_array($settings->business_name, ['My Business', ''], true)) {
            $settings->business_name = $name;
        }
        $settings->tax_number_1 = $settings->tax_number_1 ?: (trim((string) $company['TaxNumber']) ?: null);
        $settings->address = $settings->address ?: (trim((string) $company['Address']) ?: null);
        $settings->phone = $settings->phone ?: (trim((string) ($company['Tel1'] ?: $company['Mobile'])) ?: null);
        $settings->email = $settings->email ?: (trim((string) $company['Email']) ?: null);

        if (! $settings->logo_path && filled($company['Logo'] ?? null) && ($binary = base64_decode((string) $company['Logo'], true))) {
            $ext = match (substr((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary), 6)) {
                'jpeg' => 'jpg', 'gif' => 'gif', 'bmp' => 'bmp', 'webp' => 'webp', default => 'png',
            };
            Storage::disk('public')->put($path = 'branding/legacy-logo.'.$ext, $binary);
            $settings->logo_path = $path;
        }
        $settings->save();
        $context->count($this->name(), 'company');
    }

    private function locations(LegacyPackage $package, ImportContext $context): void
    {
        $branches = collect($package->all('Branch'))->keyBy(fn ($b) => (string) $b['BranchId']);
        $boxes = collect($package->all('GeneralSetting'))->mapWithKeys(fn ($g) => [(string) $g['BranchId'] => (string) $g['BoxId']]);
        $mainStore = (string) ($package->all('GeneralSetting')[0]['StoreId'] ?? '1');
        $cash = PaymentMethod::query()->where('type', PaymentMethodType::Cash)->first();
        $layouts = [
            'invoice_layout_id' => InvoiceLayout::query()->where('paper', PaperSize::A4)->orderByDesc('is_default')->value('id'),
            'receipt_layout_id' => InvoiceLayout::query()->where('paper', PaperSize::Thermal80)->orderByDesc('is_default')->value('id'),
        ];

        foreach ($package->all('Store') as $store) {
            $storeId = (string) $store['StoreId'];
            $branch = $branches[(string) $store['BranchId']] ?? [];
            $location = Location::query()->find($context->newId('Store', $storeId));
            // the main store takes over the default branch of a fresh install
            if (! $location && $storeId === $mainStore && Location::query()->count() === 1 && ! DB::table('sales')->exists()) {
                $location = Location::query()->first();
            }
            $location ??= new Location(['is_active' => true, ...$layouts]);
            $location->fill([
                'code' => 'B'.$store['BranchId'],
                'name' => trim((string) ($branch['NameA'] ?? '') ?: $store['NameA']),
                'phone' => mb_substr(trim((string) ($branch['Tel1'] ?? $store['Tel'] ?? '')), 0, 30) ?: null,
                'address' => trim((string) ($branch['Address'] ?? $store['Address'] ?? '')) ?: null,
            ])->save();
            $context->remember('Store', $storeId, $location);
            $context->remember('Branch', (string) $store['BranchId'], $location);
            $context->count($this->name(), 'locations');

            // sell everywhere with every active method; cash goes to the branch's own box
            $box = $context->newId('ChartOfAccount', $boxes[(string) $store['BranchId']] ?? '');
            foreach (PaymentMethod::query()->get() as $method) {
                $location->paymentMethods()->syncWithoutDetaching([$method->id => [
                    'account_id' => $method->is($cash) && $box ? $box : $method->account_id, 'is_enabled' => true,
                ]]);
            }
            DB::table('cash_registers')->where('location_id', $location->id)->whereNull('cash_account_id')->update(['cash_account_id' => $box]);
        }
    }

    private function registers(LegacyPackage $package, ImportContext $context): void
    {
        $boxes = collect($package->all('GeneralSetting'))->mapWithKeys(fn ($g) => [(string) $g['BranchId'] => (string) $g['BoxId']]);
        foreach ($package->all('POS') as $pos) {
            $key = ImportContext::key($pos['BranchId'], $pos['POSId']);
            $locationId = $context->newId('Branch', (string) $pos['BranchId']);
            if (! $locationId) {
                $context->warn(__('legacy.warnings.pos_without_branch', ['name' => $pos['NameA']]));

                continue;
            }
            $register = CashRegister::query()->find($context->newId('POS', $key)) ?? new CashRegister;
            $register->fill([
                'location_id' => $locationId,
                'name' => __('legacy.register_name', ['name' => trim((string) ($pos['NameA'] ?: $pos['Code']))]),
                'cash_account_id' => $context->newId('ChartOfAccount', $boxes[(string) $pos['BranchId']] ?? ''),
                'is_active' => (int) $pos['Status'] === 1,
            ])->save();
            $context->remember('POS', $key, $register);
            $context->count($this->name(), 'cash drawers');
        }
    }
}
