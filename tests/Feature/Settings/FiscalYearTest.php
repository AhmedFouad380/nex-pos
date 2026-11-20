<?php

use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\Posting;
use App\Domain\Purchasing\Actions\VoidPurchase;
use App\Domain\Purchasing\Models\Purchase;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\DomainException;
use App\Support\FiscalYear;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $id = fn (Role $role) => Account::query()->where('sub_type', $role)->orderBy('code')->value('id');
    $entry = fn (string $date, string $text) => app(RecordJournalEntry::class)->handle(
        Carbon::parse($date), $text,
        [['account_id' => $id(Role::Cash), 'debit' => 100], ['account_id' => $id(Role::Capital), 'credit' => 100]]);
    $this->old = $entry((now()->year - 2).'-05-10', 'قيد قديم');
    $this->new = $entry(now()->toDateString(), 'قيد السنة');
});

it('shows one fiscal year at a time and switches back from the top bar', function () {
    livewire(ListJournalEntries::class)->assertCanSeeTableRecords([$this->new])->assertCanNotSeeTableRecords([$this->old]);

    $oldYear = now()->year - 2;
    expect(FiscalYear::years())->toHaveKeys([now()->year, now()->year - 1, $oldYear]);
    $this->post(route('fiscal-year.switch'), ['year' => $oldYear])->assertRedirect();
    expect(FiscalYear::selected())->toBe($oldYear)->and(FiscalYear::isCurrent())->toBeFalse();

    livewire(ListJournalEntries::class)->assertCanSeeTableRecords([$this->old])->assertCanNotSeeTableRecords([$this->new]);
    $page = livewire(TrialBalance::class);
    expect($page->get('from'))->toBe($oldYear.'-01-01')->and($page->get('to'))->toBe($oldYear.'-12-31');
    $this->get(ListJournalEntries::getUrl())->assertOk()->assertSee(__('Back to the current year'));

    $this->post(route('fiscal-year.switch'), ['year' => now()->year]);
    expect(FiscalYear::isCurrent())->toBeTrue();
    $this->post(route('fiscal-year.switch'), ['year' => 1990])->assertStatus(422);
});

it('follows the fiscal year start month', function () {
    $settings = app(GeneralSettings::class);
    $settings->fy_start_month = 7;
    $settings->save();

    expect(FiscalYear::of(Carbon::parse('2026-03-01')))->toBe(2025)
        ->and(FiscalYear::of(Carbon::parse('2026-07-01')))->toBe(2026)
        ->and(FiscalYear::label(2025))->toBe('2025/2026')
        ->and(FiscalYear::range(2025)[0]->toDateString())->toBe('2025-07-01')
        ->and(FiscalYear::range(2025)[1]->toDateString())->toBe('2026-06-30');
});

it('locks past years for users, but not for the system or those allowed', function () {
    $tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    $purchase = makePurchase(makeSupplier(), [[$tea, 5, 6]], ['date' => now()->subYear()->startOfYear()->addDays(2)]);
    $cashier = User::factory()->create();
    $cashier->assignRole('accountant');
    $this->actingAs($cashier);
    $record = fn (string $date) => app(RecordJournalEntry::class)->handle(
        Carbon::parse($date), 'تجربة', [
            ['account_id' => Account::query()->where('sub_type', Role::Cash)->value('id'), 'debit' => 10],
            ['account_id' => Account::query()->where('sub_type', Role::Capital)->value('id'), 'credit' => 10]]);

    expect(fn () => $record((now()->year - 1).'-12-31'))->toThrow(DomainException::class, (string) (now()->year - 1))
        ->and($record(now()->toDateString()))->toBeInstanceOf(JournalEntry::class)
        ->and(fn () => app(Posting::class)->reverse($this->old))->toThrow(DomainException::class);

    // void a purchase dated last year (entered by the admin back then): refused as well
    expect(fn () => app(VoidPurchase::class)->handle($purchase, 'خطأ'))->toThrow(DomainException::class);

    $cashier->givePermissionTo('fiscal_year.edit_closed');
    expect($record((now()->year - 1).'-12-31'))->toBeInstanceOf(JournalEntry::class);

    $settings = app(GeneralSettings::class);
    $settings->lock_past_years = false;
    $settings->save();
    $cashier->revokePermissionTo('fiscal_year.edit_closed');
    expect($record((now()->year - 1).'-12-30'))->toBeInstanceOf(JournalEntry::class);
});
