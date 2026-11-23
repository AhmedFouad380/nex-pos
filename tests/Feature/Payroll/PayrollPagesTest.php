<?php

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Actions\GiveAdvance;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\PayrollRun;
use App\Filament\Admin\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Filament\Admin\Resources\EmployeeAdvances\Pages\ViewEmployeeAdvance;
use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Filament\Admin\Resources\Employees\Pages\EmployeeStatement;
use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Admin\Resources\Employees\RelationManagers\AdvancesRelationManager;
use App\Filament\Admin\Resources\Employees\RelationManagers\PaymentsRelationManager;
use App\Filament\Admin\Resources\Employees\RelationManagers\PayrollLinesRelationManager;
use App\Filament\Admin\Resources\Employees\Widgets\EmployeeOverview;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ListPaymentVouchers;
use App\Filament\Admin\Resources\PayrollRuns\Pages\CreatePayrollRun;
use App\Filament\Admin\Resources\PayrollRuns\Pages\EditPayrollRun;
use App\Filament\Admin\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Admin\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
});

it('creates an employee with salary data and gives an advance from their page', function () {
    livewire(CreateEmployee::class)
        ->fillForm(['name' => 'أحمد علي', 'type' => 'employee', 'is_active' => true,
            'employeeProfile' => ['job_title' => 'كاشير', 'location_id' => $this->location->id, 'status' => 'active',
                'base_salary' => 6000, 'fixed_allowances' => 400, 'hire_date' => '2025-01-01']])
        ->call('create')->assertHasNoFormErrors();

    $ahmed = Contact::query()->where('name', 'أحمد علي')->sole();
    expect($ahmed->employeeProfile->base_salary)->toBe('6000.0000');

    livewire(ListEmployees::class)->assertOk()->assertCanSeeTableRecords([$ahmed])->assertSee('كاشير');
    livewire(EditEmployee::class, ['record' => $ahmed->id])->assertOk()
        ->fillForm(['employeeProfile.base_salary' => 6500])->call('save')->assertHasNoFormErrors();
    expect($ahmed->refresh()->employeeProfile->base_salary)->toBe('6500.0000');

    livewire(ViewEmployee::class, ['record' => $ahmed->id])->assertOk()
        ->callAction('giveAdvance', ['location_id' => $this->location->id, 'amount' => 900, 'installment_amount' => 300,
            'payment_method_id' => cashMethodId(), 'advance_date' => now()->toDateTimeString()])
        ->assertHasNoActionErrors();

    expect(EmployeeAdvance::query()->sole()->remaining)->toBe('900.0000')
        ->and($ahmed->refresh()->balance)->toBe('900.0000');
});

it('lists advances, repays one in cash and voids a fresh one', function () {
    $employee = makeEmployee('5000');
    $advance = app(GiveAdvance::class)->handle(['contact_id' => $employee->id, 'location_id' => $this->location->id,
        'amount' => 600, 'payment_method_id' => cashMethodId()]);

    livewire(ListEmployeeAdvances::class)->assertOk()->assertCanSeeTableRecords([$advance]);
    livewire(ViewEmployeeAdvance::class, ['record' => $advance->id])->assertOk()
        ->callAction('repay', ['amount' => 200, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString()])
        ->assertHasNoActionErrors();
    expect($advance->refresh()->remaining)->toBe('400.0000');

    $fresh = app(GiveAdvance::class)->handle(['contact_id' => $employee->id, 'location_id' => $this->location->id,
        'amount' => 100, 'payment_method_id' => cashMethodId()]);
    livewire(ViewEmployeeAdvance::class, ['record' => $fresh->id])->callAction('void', ['reason' => 'غلط']);
    expect($fresh->refresh()->voided_at)->not->toBeNull();
});

it('runs a month: create, edit lines, approve, pay all, print', function () {
    $employee = makeEmployee('3000');
    makeEmployee('4500');
    app(GiveAdvance::class)->handle(['contact_id' => $employee->id, 'location_id' => $this->location->id,
        'amount' => 500, 'payment_method_id' => cashMethodId(), 'advance_date' => now()->subMonth()]);

    $other = makeEmployee('2000', ['location_id' => Location::query()->create(['name' => 'فرع تاني', 'code' => 'BR2'])->id]);

    // picking the branch lists its employees with salary, open advances and this month's installment
    $create = livewire(CreatePayrollRun::class)
        ->fillForm(['period' => now()->startOfMonth()->toDateString(), 'location_id' => $this->location->id]);
    $rows = collect($create->get('data.lines'));
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('contact_id')->all())->not->toContain($other->id);
    $key = $rows->search(fn ($r) => $r['contact_id'] === $employee->id);
    expect($rows[$key]['open_advances'])->toBe('500.00')
        ->and($rows[$key]['advance_deduction'])->toEqual(500)
        ->and($rows[$key]['net'])->toBe('2500.00');

    // adjust an amount and leave the other employee out before saving
    $create->set("data.lines.{$key}.bonus", 250)
        ->assertSet("data.lines.{$key}.net", '2750.00')
        ->set('data.lines', [$key => $create->get("data.lines.{$key}")])
        ->call('create')->assertHasNoFormErrors();

    $run = PayrollRun::query()->sole();
    expect($run->lines)->toHaveCount(1)
        ->and($run->lines->first()->net)->toBe('2750.0000'); // 3000 + 250 − 500 advance

    // the draft can still be edited, and the left-out employee added back
    livewire(EditPayrollRun::class, ['record' => $run->id])->assertOk()->callAction('addMissing');
    expect($run->refresh()->lines)->toHaveCount(2);

    livewire(ViewPayrollRun::class, ['record' => $run->id])->assertOk()
        ->callAction('approve')
        ->callAction('pay', ['line_ids' => $run->refresh()->lines->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString()])
        ->assertHasNoActionErrors();

    expect($run->refresh()->status)->toBe(PayrollStatus::Paid);
    livewire(ListPayrollRuns::class)->assertOk()->assertCanSeeTableRecords([$run]);
    livewire(EmployeeStatement::class, ['record' => $employee->id])->assertOk()->assertSee($run->number);

    $this->get(route('payroll.print', $run))->assertOk()->assertSee('2,750.00');
    $this->get(route('payroll.payslips', $run))->assertOk()->assertSee($employee->name);
});

it('hides payroll from users without the permission', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);

    livewire(ListPayrollRuns::class)->assertForbidden();
    livewire(ListEmployeeAdvances::class)->assertForbidden();
});

it('shows an advance as a payment voucher, and the employee page lists their vouchers, advances and figures', function () {
    $employee = makeEmployee('5000');
    $advance = app(GiveAdvance::class)->handle(['contact_id' => $employee->id, 'location_id' => $this->location->id,
        'amount' => 750, 'installment_amount' => 250, 'payment_method_id' => cashMethodId()]);
    $voucher = $advance->payment;

    // the advance is a payment voucher like any other: in the payment vouchers list, paid to the employee
    expect($voucher->direction)->toBe(PaymentDirection::Out)->and($voucher->contact_id)->toBe($employee->id)
        ->and((string) $voucher->amount)->toBe('750.0000');
    livewire(ListPaymentVouchers::class)->assertCanSeeTableRecords([$voucher]);

    // the employee page: the year's figures, then the vouchers, advances and salaries tabs
    livewire(ViewEmployee::class, ['record' => $employee->id])->assertOk();
    livewire(EmployeeOverview::class, ['record' => $employee])->assertOk()
        ->assertSee(__('Paid to the employee'))->assertSee('750.00')->assertSee(__('Open advances'));
    livewire(PaymentsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertCanSeeTableRecords([$voucher]);
    livewire(AdvancesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertCanSeeTableRecords([$advance])->assertSee($voucher->number);
    livewire(PayrollLinesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])->assertOk();

    // and the account statement shows the voucher
    livewire(EmployeeStatement::class, ['record' => $employee->id])->assertOk()->assertSee($voucher->number);
});
