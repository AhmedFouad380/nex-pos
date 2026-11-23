<?php

use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Actions\AdvanceRequestWorkflow;
use App\Domain\Payroll\Actions\LinkUserToEmployee;
use App\Domain\Payroll\Enums\AdvanceRequestStatus;
use App\Domain\Payroll\Models\AdvanceRequest;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Filament\Admin\Resources\AdvanceRequests\Pages\ManageAdvanceRequests;
use App\Filament\Admin\Resources\Settings\Pages\ManageUsers;
use App\Filament\Pos\Pages\PointOfSale;
use App\Models\User;
use App\Support\DomainException;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->admin = User::query()->where('email', config('newpos.seed_admin.email'))->firstOrFail();
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cashier');
    $this->cashier->locations()->attach(Location::query()->firstOrFail()->id);
    $this->employee = makeEmployee('6000');
    $this->employee->employeeProfile->update(['user_id' => $this->cashier->id]);
});

it('runs a request from the cash register to approval and payment', function () {
    Filament::setCurrentPanel('pos');
    $this->actingAs($this->cashier);
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);
    expect($page->instance()->bootstrap()['can']['requestAdvance'])->toBeTrue();

    expect($page->instance()->requestAdvance(1500, 500, 'ظرف طارئ'))->toBe(['ok' => true])
        ->and($page->instance()->requestAdvance(200)['error'] ?? null)->toBe(__('payroll.errors.request_pending'));
    $request = AdvanceRequest::query()->sole();
    expect($request->status)->toBe(AdvanceRequestStatus::Pending)->and($request->contact_id)->toBe($this->employee->id)
        ->and($this->admin->notifications()->count())->toBe(1);

    // the manager approves a smaller amount, then pays it
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
    livewire(ManageAdvanceRequests::class)->assertCanSeeTableRecords([$request])
        ->callTableAction('approve', $request, ['amount' => 1000, 'installment_amount' => 250, 'note' => 'الحد المتاح'])
        ->assertHasNoTableActionErrors();
    expect($request->refresh()->status)->toBe(AdvanceRequestStatus::Approved)
        ->and($this->cashier->notifications()->count())->toBe(1);

    livewire(ManageAdvanceRequests::class)
        ->callTableAction('pay', $request, ['payment_method_id' => cashMethodId(), 'location_id' => $request->location_id, 'date' => now()->toDateTimeString()])
        ->assertHasNoTableActionErrors();

    $advance = EmployeeAdvance::query()->sole();
    expect($request->refresh()->status)->toBe(AdvanceRequestStatus::Paid)
        ->and($request->employee_advance_id)->toBe($advance->id)
        ->and((float) $advance->amount)->toBe(1000.0)->and((float) $advance->installment_amount)->toBe(250.0)
        ->and($advance->payment_id)->not->toBeNull()
        ->and($this->cashier->notifications()->count())->toBe(2);
});

it('lets the employee see only their own requests and never approve them', function () {
    $other = makeEmployee('5000');
    $flow = app(AdvanceRequestWorkflow::class);
    $mine = $flow->request($this->cashier, $this->employee, 300);
    $theirs = $flow->request($this->admin, $other, 400);

    Filament::setCurrentPanel('admin');
    $this->cashier->givePermissionTo('admin.access');
    $this->actingAs($this->cashier);
    livewire(ManageAdvanceRequests::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
        ->assertTableActionHidden('approve', $mine)->assertTableActionHidden('pay', $mine)
        ->callTableAction('cancel', $mine);
    expect($mine->refresh()->status)->toBe(AdvanceRequestStatus::Cancelled);
});

it('rejects with a reason, and refuses paying what was not approved', function () {
    $flow = app(AdvanceRequestWorkflow::class);
    $request = $flow->request($this->cashier, $this->employee, 300);

    expect(fn () => $flow->pay($request, cashMethodId(), $request->location_id))->toThrow(DomainException::class);
    $flow->reject($request, $this->admin, 'عليه سلفة مفتوحة');

    expect($request->refresh()->status)->toBe(AdvanceRequestStatus::Rejected)->and($request->decision_note)->toBe('عليه سلفة مفتوحة')
        ->and(fn () => $flow->approve($request, $this->admin))->toThrow(DomainException::class)
        ->and(fn () => $flow->request($this->cashier, $this->employee, 100, 200))->toThrow(DomainException::class); // installment > amount
});

it('lets a manager approve a request they entered for an employee, but nobody their own', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
    $request = app(AdvanceRequestWorkflow::class)->request($this->admin, $this->employee, 500);
    // the admin entered it, but it is the employee's advance: the admin decides on it and was told about it
    livewire(ManageAdvanceRequests::class)->assertTableActionVisible('approve', $request)
        ->callTableAction('approve', $request, ['amount' => 500])->assertHasNoTableActionErrors();
    expect($request->refresh()->status)->toBe(AdvanceRequestStatus::Approved);

    // the admin's own advance (linked to an employee record): no deciding on it, even directly
    $own = makeEmployee('4000');
    app(LinkUserToEmployee::class)->handle($this->admin, $own);
    $mine = app(AdvanceRequestWorkflow::class)->request($this->admin, $own, 300);
    livewire(ManageAdvanceRequests::class)->assertTableActionHidden('approve', $mine);
    expect(fn () => app(AdvanceRequestWorkflow::class)->approve($mine, $this->admin))->toThrow(DomainException::class);
});

it('shows the advance button to a cashier not linked to an employee, with a note', function () {
    Filament::setCurrentPanel('pos');
    $other = User::factory()->create();
    $other->assignRole('cashier');
    $other->locations()->attach(Location::query()->firstOrFail()->id);
    $this->actingAs($other);
    openSession($other);
    $can = livewire(PointOfSale::class)->instance()->bootstrap()['can'];
    expect($can['requestAdvance'])->toBeTrue()->and($can['advanceLinked'])->toBeFalse();

    // linking from the users page
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
    $employee = makeEmployee('3000');
    livewire(ManageUsers::class)->callTableAction('linkEmployee', $other, ['contact_id' => $employee->id])->assertHasNoTableActionErrors();
    expect($other->refresh()->employeeProfile->contact_id)->toBe($employee->id);
});
