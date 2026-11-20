<?php

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Models\Account;
use App\Domain\Foundation\Models\Location;
use App\Models\User;
use App\Settings\InventorySettings;
use Illuminate\Support\Facades\DB;

it('seeds everything a fresh install needs', function () {
    seedBaseData();

    expect(Location::query()->count())->toBe(1)
        ->and(Contact::query()->where('is_default', true)->count())->toBe(1)
        ->and(User::query()->first()->isSuperAdmin())->toBeTrue()
        ->and(app(InventorySettings::class)->default_unit_id)->not->toBeNull();

    // every sub type posting rules depend on exists exactly once, as a postable system account
    foreach ([AccountSubType::Cash, AccountSubType::Receivable, AccountSubType::Payable, AccountSubType::Inventory,
        AccountSubType::Cogs, AccountSubType::Sales, AccountSubType::TaxOutput, AccountSubType::TaxInput,
        AccountSubType::CustomerAdvance, AccountSubType::OpeningBalance] as $subType) {
        $accounts = Account::query()->where('sub_type', $subType)->get();
        expect($accounts)->toHaveCount(1, "sub type {$subType->value}")
            ->and($accounts->first()->is_postable)->toBeTrue()
            ->and($accounts->first()->is_system)->toBeTrue();
    }
});

it('is idempotent — re-seeding a live install changes nothing', function () {
    seedBaseData();
    $tables = ['permissions', 'roles', 'role_has_permissions', 'accounts', 'units', 'payment_methods', 'locations',
        'cash_registers', 'document_sequences', 'contacts', 'users', 'currencies', 'expense_categories', 'notification_templates'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

    seedBaseData();

    expect(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]))->toEqual($before);
});

it('keeps an account name the client renamed', function () {
    seedBaseData();
    Account::query()->where('code', '1101')->update(['name' => 'خزنة المحل']);

    seedBaseData();

    expect(Account::query()->where('code', '1101')->value('name'))->toBe('خزنة المحل');
});
