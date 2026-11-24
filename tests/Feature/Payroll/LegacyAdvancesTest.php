<?php

use App\Domain\CRM\Actions\SaveContact;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Actions\OpenAdvancesFromBalances;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedBaseData();
    // the old system's salary entries are imported as they were; this stand-in credits the employee off the employee account
    $this->skipLedgerCheck = true;
    $this->actingAs(User::query()->firstOrFail());
    $this->location = Location::query()->firstOrFail();
    $this->employee = app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'موظف قديم']);
    $this->pay = fn ($contact, string $amount, string $date) => app(RecordPayment::class)->handle(new PaymentData(
        PaymentDirection::Out, cashMethodId(), $amount, $contact->id, $this->location->id, Carbon::parse($date)));
    // the old system's salary: a journal entry crediting the employee
    $this->salary = fn ($contact, string $amount, string $date) => app(RecordJournalEntry::class)->handle(Carbon::parse($date), 'مرتب', [
        ['account_id' => Account::query()->where('sub_type', Role::Cash)->orderBy('code')->value('id'), 'debit' => $amount],
        ['account_id' => Account::query()->where('sub_type', Role::Capital)->orderBy('code')->value('id'), 'credit' => $amount, 'contact_id' => $contact->id],
    ]);
});

it('turns what an employee owes into one advance per payment, newest first, the oldest in part', function () {
    ($this->pay)($this->employee, '1000', now()->subDays(40)->toDateString());
    ($this->salary)($this->employee, '900', now()->subDays(30)->toDateString());
    $middle = ($this->pay)($this->employee, '500', now()->subDays(20)->toDateString());
    $newest = ($this->pay)($this->employee, '300', now()->subDays(5)->toDateString());
    expect($this->employee->refresh()->balance)->toBe('900.0000'); // 1800 paid − 900 salary

    $report = app(OpenAdvancesFromBalances::class)->handle();

    $advances = EmployeeAdvance::query()->where('contact_id', $this->employee->id)->orderByDesc('advance_date')->get();
    expect($report)->toHaveCount(1)->and($report[0]['advances'])->toBe(3)
        ->and($advances->pluck('amount')->all())->toBe(['300.0000', '500.0000', '100.0000'])
        ->and($advances[0]->payment_id)->toBe($newest->id)->and($advances[1]->payment_id)->toBe($middle->id)
        ->and($advances[2]->note)->toContain('1,000.00')
        ->and((string) app(AdvanceBalances::class)->openTotal($this->employee))->toBe('900.0000')
        ->and(Payment::query()->count())->toBe(3) // no money moved again
        ->and($this->employee->refresh()->balance)->toBe('900.0000')
        ->and($this->employee->employeeProfile->status)->toBe(EmployeeStatus::Active)
        ->and($this->employee->employeeProfile->location_id)->toBe($this->location->id);

    expect(app(OpenAdvancesFromBalances::class)->handle())->toBeEmpty(); // already done: left alone
});

it('skips accounts that are not people, settled employees, and marks long-idle ones suspended', function () {
    $till = app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'كاش المحل', 'code' => 'E-TILL']);
    ($this->pay)($till, '800', now()->subDays(3)->toDateString());
    $settled = app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'موظف مسدد']);
    ($this->pay)($settled, '400', now()->subDays(10)->toDateString());
    ($this->salary)($settled, '400', now()->subDays(2)->toDateString());
    ($this->pay)($this->employee, '250', now()->subDays(400)->toDateString());

    expect($settled->refresh()->balance)->toBe('0.0000'); // the salary entry updated the cached balance

    $report = collect(app(OpenAdvancesFromBalances::class)->handle(['E-TILL'], dryRun: true));
    expect($report->pluck('employee')->all())->toBe(['موظف قديم'])
        ->and($report[0]['profile'])->toBe(EmployeeStatus::Suspended->value)
        ->and(EmployeeAdvance::query()->count())->toBe(0); // dry run
});
