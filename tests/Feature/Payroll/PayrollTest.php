<?php

use App\Domain\CRM\Actions\SaveContact;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Actions\ApprovePayroll;
use App\Domain\Payroll\Actions\GeneratePayroll;
use App\Domain\Payroll\Actions\GiveAdvance;
use App\Domain\Payroll\Actions\PayPayroll;
use App\Domain\Payroll\Actions\ReopenPayroll;
use App\Domain\Payroll\Actions\RepayAdvance;
use App\Domain\Payroll\Actions\UpdatePayrollLines;
use App\Domain\Payroll\Actions\VoidAdvance;
use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Support\DomainException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedBaseData();
    $this->location = Location::query()->firstOrFail();
    $this->month = Carbon::parse('2026-10-01');
    $this->employee = function (string $salary, array $profile = [], array $contact = []): Contact {
        $employee = app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'موظف '.uniqid(), ...$contact]);
        $employee->employeeProfile()->create(['location_id' => $this->location->id, 'base_salary' => $salary, ...$profile]);

        return $employee->refresh();
    };
    $this->advance = fn (Contact $employee, string $amount, ?string $installment = null, string $date = '2026-09-15') => app(GiveAdvance::class)->handle([
        'contact_id' => $employee->id, 'location_id' => $this->location->id, 'amount' => $amount, 'installment_amount' => $installment,
        'payment_method_id' => cashMethodId(), 'advance_date' => Carbon::parse($date),
    ]);
});

it('gives an advance as a payment voucher that the employee owes back', function () {
    $ali = ($this->employee)('5000');
    $cashBefore = accountBalance(Role::Cash);

    $advance = ($this->advance)($ali, '1200', '400');

    expect($advance->number)->toBe('ADV-00001')
        ->and($advance->payment->number)->toBe('PV-00001')
        ->and($advance->remaining)->toBe('1200.0000')
        ->and($advance->status)->toBe(AdvanceStatus::Open)
        ->and($ali->refresh()->balance)->toBe('1200.0000') // he owes us
        ->and(accountBalance(Role::Cash))->toEqualWithDelta($cashBefore - 1200, 0.001)
        ->and(accountBalance(Role::EmployeeAccounts))->toEqualWithDelta(1200, 0.001);
});

it('generates a draft for active employees with allowances and advance installments', function () {
    $ali = ($this->employee)('5000', ['fixed_allowances' => '500']);
    $sara = ($this->employee)('4000');
    ($this->employee)('3000', ['status' => EmployeeStatus::Terminated]);
    ($this->employee)('3000', ['hire_date' => '2026-11-05']);   // starts next month
    app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'بدون ملف']);  // no salary data
    ($this->advance)($ali, '1200', '400');
    ($this->advance)($sara, '300');                                              // no installment: all of it

    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);

    expect($run->number)->toBe('PAY-00001')
        ->and($run->status)->toBe(PayrollStatus::Draft)
        ->and($run->posting_date->toDateString())->toBe('2026-10-31')
        ->and($run->lines)->toHaveCount(2);
    $aliLine = $run->lines->firstWhere('contact_id', $ali->id);
    expect($aliLine->earned)->toBe('5500.0000')
        ->and($aliLine->advance_deduction)->toBe('400.0000')
        ->and($aliLine->net)->toBe('5100.0000')
        ->and($run->lines->firstWhere('contact_id', $sara->id)->advance_deduction)->toBe('300.0000')
        ->and($run->total_net)->toBe('8800.0000');

    // nothing posts while it is a draft
    expect(accountBalance(Role::Salaries))->toBe(0.0);
});

it('refuses a second payroll for the same month and employee', function () {
    ($this->employee)('5000');
    app(GeneratePayroll::class)->handle($this->month, $this->location->id);

    expect(fn () => app(GeneratePayroll::class)->handle($this->month, null))->toThrow(DomainException::class);
});

it('edits lines, approves, pays everyone at once and settles the advance', function () {
    $ali = ($this->employee)('6000');
    $sara = ($this->employee)('3000');
    $advance = ($this->advance)($ali, '1000', '500');

    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);
    $lines = $run->lines->keyBy('contact_id');
    app(UpdatePayrollLines::class)->handle($run, [
        $lines[$ali->id]->id => [...$lines[$ali->id]->only(['base_salary', 'allowances', 'advance_deduction']),
            'overtime' => '250', 'absence_days' => '2', 'absence_deduction' => (string) PayrollCalculator::absenceDeduction('6000', 2)],
        $lines[$sara->id]->id => [...$lines[$sara->id]->only(['base_salary']), 'bonus' => '100', 'penalties' => '50', 'note' => 'تأخير'],
    ]);
    $line = $run->lines()->where('contact_id', $ali->id)->first();
    expect($line->earned)->toBe('5850.0000')   // 6000 + 250 − 400
        ->and($line->net)->toBe('5350.0000');  // − 500 advance

    app(ApprovePayroll::class)->handle($run);
    expect($run->refresh()->status)->toBe(PayrollStatus::Approved)
        ->and(accountBalance(Role::Salaries))->toEqualWithDelta(5850 + 3050, 0.001)
        ->and($advance->refresh()->remaining)->toBe('500.0000')
        ->and($ali->refresh()->balance)->toBe('-4850.0000'); // 1000 advance − 5850 earned: we owe him 4850

    expect(fn () => app(UpdatePayrollLines::class)->handle($run, []))->toThrow(DomainException::class); // locked

    $paid = app(PayPayroll::class)->handle($run, cashMethodId());
    expect($paid)->toBe(2)
        ->and($run->refresh()->status)->toBe(PayrollStatus::Paid)
        ->and($run->total_paid)->toBe('8400.0000')
        ->and($ali->refresh()->balance)->toBe('500.0000')   // the rest of the advance
        ->and($sara->refresh()->balance)->toBe('0.0000');

    $rows = app(ContactLedger::class)->statement($ali)['rows'];
    expect($rows->pluck('kind')->all())->toBe(['payment', 'payroll', 'payment']);
});

it('pays some employees now and the rest later; voiding a payout makes it unpaid again', function () {
    $ali = ($this->employee)('5000');
    ($this->employee)('4000');
    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);
    app(ApprovePayroll::class)->handle($run);

    $aliLine = $run->lines()->where('contact_id', $ali->id)->first();
    app(PayPayroll::class)->handle($run, cashMethodId(), [$aliLine->id]);
    expect($run->refresh()->status)->toBe(PayrollStatus::PartiallyPaid);

    app(VoidPayment::class)->handle($aliLine->refresh()->payment, 'غلط');
    expect($aliLine->refresh()->payment_id)->toBeNull()
        ->and($run->refresh()->status)->toBe(PayrollStatus::Approved);

    app(PayPayroll::class)->handle($run, cashMethodId());
    expect($run->refresh()->status)->toBe(PayrollStatus::Paid)
        ->and(fn () => app(PayPayroll::class)->handle($run, cashMethodId()))->toThrow(DomainException::class);
});

it('reopens an unpaid payroll, giving the advance deduction back', function () {
    $ali = ($this->employee)('5000');
    $advance = ($this->advance)($ali, '800');
    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);
    app(ApprovePayroll::class)->handle($run);
    expect($advance->refresh()->status)->toBe(AdvanceStatus::Settled);

    app(ReopenPayroll::class)->handle($run);

    expect($run->refresh()->status)->toBe(PayrollStatus::Draft)
        ->and($advance->refresh()->remaining)->toBe('800.0000')
        ->and(accountBalance(Role::Salaries))->toEqualWithDelta(0, 0.001)
        ->and($ali->refresh()->balance)->toBe('800.0000');

    app(ApprovePayroll::class)->handle($run);
    app(PayPayroll::class)->handle($run, cashMethodId());
    expect(fn () => app(ReopenPayroll::class)->handle($run))->toThrow(DomainException::class)
        ->and(fn () => app(ReopenPayroll::class)->void($run, 'x'))->toThrow(DomainException::class);
});

it('voids a payroll and frees its month', function () {
    ($this->employee)('5000');
    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);
    app(ApprovePayroll::class)->handle($run);

    app(ReopenPayroll::class)->void($run, 'مكرر');

    expect($run->refresh()->voided_at)->not->toBeNull()
        ->and(accountBalance(Role::Salaries))->toEqualWithDelta(0, 0.001);
    expect(app(GeneratePayroll::class)->handle($this->month, $this->location->id)->lines)->toHaveCount(1);
});

it('rejects deductions above what is owed', function () {
    $ali = ($this->employee)('1000');
    ($this->advance)($ali, '300');
    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);
    $line = $run->lines->first();

    expect(fn () => app(UpdatePayrollLines::class)->handle($run, [$line->id => ['base_salary' => '1000', 'penalties' => '1200']]))
        ->toThrow(DomainException::class)
        ->and(fn () => app(UpdatePayrollLines::class)->handle($run, [$line->id => ['base_salary' => '1000', 'advance_deduction' => '1100']]))
        ->toThrow(DomainException::class);

    // the advance got repaid in cash meanwhile: approval re-checks what is still open
    $advance = EmployeeAdvance::query()->sole();
    app(RepayAdvance::class)->handle($advance, '300', cashMethodId());
    expect(fn () => app(ApprovePayroll::class)->handle($run))->toThrow(DomainException::class)
        ->and($run->refresh()->status)->toBe(PayrollStatus::Draft);
});

it('repays an advance in cash and protects advance vouchers from being voided directly', function () {
    $ali = ($this->employee)('5000');
    $advance = ($this->advance)($ali, '1000');

    expect(fn () => app(VoidPayment::class)->handle($advance->payment, 'x'))->toThrow(DomainException::class);

    $repayment = app(RepayAdvance::class)->handle($advance, '400', cashMethodId());
    expect($advance->refresh()->remaining)->toBe('600.0000')
        ->and($ali->refresh()->balance)->toBe('600.0000')
        ->and(fn () => app(RepayAdvance::class)->handle($advance, '700', cashMethodId()))->toThrow(DomainException::class)
        ->and(fn () => app(VoidAdvance::class)->handle($advance, 'x'))->toThrow(DomainException::class);

    app(VoidAdvance::class)->voidRepayment($repayment, 'غلط');
    expect($advance->refresh()->remaining)->toBe('1000.0000');

    app(VoidAdvance::class)->handle($advance, 'اتلغت');
    expect($advance->refresh()->voided_at)->not->toBeNull()
        ->and($advance->payment->voided_at)->not->toBeNull()
        ->and($ali->refresh()->balance)->toBe('0.0000');
});

it('replays payroll entries in ledger:rebuild', function () {
    ($this->employee)('5000');
    $run = app(GeneratePayroll::class)->handle($this->month, $this->location->id);
    app(ApprovePayroll::class)->handle($run);
    app(PayPayroll::class)->handle($run, cashMethodId());

    $this->artisan('ledger:rebuild', ['--force' => true])->assertSuccessful();

    expect(accountBalance(Role::Salaries))->toEqualWithDelta(5000, 0.001)
        ->and(PayrollRun::query()->sole()->journalEntries()->count())->toBe(1);
});
