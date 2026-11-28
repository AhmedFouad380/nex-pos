<?php

use App\Domain\CashManagement\Actions\AdjustBoxToCount;
use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Actions\ReceiveShiftCash;
use App\Domain\CashManagement\Services\BranchCash;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Pages\Reports\DailyCashClosePage;
use App\Filament\Admin\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Admin\Resources\CashSessions\Pages\ViewCashSession;
use App\Filament\Admin\Resources\Treasuries\Pages\ManageTreasuries;
use App\Filament\Admin\Resources\Treasuries\Pages\TreasuryStatement;
use App\Filament\Admin\Resources\Treasuries\TreasuryResource;
use App\Filament\Admin\Widgets\BranchCashBalances;
use App\Models\User;
use App\Support\DomainException;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->cash = app(BranchCash::class);
    $this->branchBox = Account::query()->findOrFail($this->cash->boxFor($this->location->id));
    $this->balance = fn (Account $box) => (float) (string) $this->cash->balances([$box->id])[$box->id];

    // a shift that sold 100 in cash and closed with 100 counted
    $tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 20, 6]]);
    $this->session = openSession();
    sell([[$tea, 10]], [['payment_method_id' => cashMethodId(), 'amount' => 100]], ['cashSessionId' => $this->session->id]);
    $this->session = app(CloseCashSession::class)->handle($this->session, 100);
    $this->main = TreasuryResource::createBox(['name' => 'الخزينة الرئيسية', 'sub_type' => 'cash']);
});

it('adds a box of its own with the next code under the other boxes', function () {
    expect($this->main->parent_id)->toBe($this->branchBox->parent_id)
        ->and($this->main->is_postable)->toBeTrue()
        ->and($this->cash->branchesByBox())->not->toHaveKey($this->main->id);

    livewire(ManageTreasuries::class)->assertOk()->assertCanSeeTableRecords([$this->main, $this->branchBox])
        ->assertSee($this->location->name);
});

it('receives a closed shift into another box, leaving a float, and can undo it', function () {
    $before = ($this->balance)($this->branchBox);

    livewire(ListCashSessions::class)->filterTable('awaiting_receipt')->assertCanSeeTableRecords([$this->session]);
    livewire(ViewCashSession::class, ['record' => $this->session->id])->assertOk()->assertSee('لم يتم استلام نقدية هذه الوردية بعد')
        ->callAction('receive', ['amount' => 70, 'kept_float' => 20, 'to_account_id' => $this->main->id])
        ->assertHasNoActionErrors()->assertSee('فرق عند الاستلام');

    $session = $this->session->refresh();
    expect($session->awaitsReceipt())->toBeFalse()
        ->and((float) $session->received_amount)->toBe(70.0)
        ->and((float) ReceiveShiftCash::shortfall($session))->toBe(10.0)
        ->and(($this->balance)($this->branchBox))->toBe($before - 70)
        ->and(($this->balance)($this->main))->toBe(70.0);

    livewire(TreasuryStatement::class, ['record' => $this->main->id])->assertOk()->assertSee('استلام نقدية وردية')->assertSee('70.00');
    livewire(TreasuryStatement::class, ['record' => $this->branchBox->id])->assertOk()->assertSee('مبيعات');

    livewire(ViewCashSession::class, ['record' => $this->session->id])->callAction('cancelReceipt')->assertHasNoActionErrors();
    expect($this->session->refresh()->awaitsReceipt())->toBeTrue()
        ->and(AccountTransfer::query()->sole()->voided_at)->not->toBeNull()
        ->and(($this->balance)($this->branchBox))->toBe($before);
});

it('refuses to receive more than was counted, or a shift still open', function () {
    expect(fn () => app(ReceiveShiftCash::class)->handle($this->session, 90, $this->main->id, 20))
        ->toThrow(DomainException::class, 'أكبر من النقدية المعدودة');

    $open = openSession();
    expect(fn () => app(ReceiveShiftCash::class)->handle($open, 10, $this->main->id))->toThrow(DomainException::class);
});

it('makes a new box the cash box of a branch, but not while a shift is open there', function () {
    $open = openSession();
    expect(fn () => $this->cash->assign($this->main, $this->location->id))->toThrow(DomainException::class, 'وردية مفتوحة');

    app(CloseCashSession::class)->handle($open, 0);
    $this->cash->assign($this->main, $this->location->id);
    expect($this->cash->boxFor($this->location->id))->toBe($this->main->id);
});

it('brings a box to what was counted, the difference on a chosen account', function () {
    $book = ($this->balance)($this->branchBox);
    $settlement = Account::query()->where('is_postable', true)->whereNull('sub_type')->where('type', 'equity')->value('id')
        ?? Account::query()->where('sub_type', 'capital')->value('id');

    app(AdjustBoxToCount::class)->handle($this->branchBox, $book - 30, (int) $settlement, now());
    expect(($this->balance)($this->branchBox))->toBe($book - 30)
        ->and(app(AdjustBoxToCount::class)->handle($this->branchBox, $book - 30, (int) $settlement, now()))->toBeNull();

    openSession();
    expect(fn () => app(AdjustBoxToCount::class)->handle($this->branchBox, 0, (int) $settlement, now()))->toThrow(DomainException::class);
});

it('shows each branch cash on the dashboard and the day close report', function () {
    livewire(BranchCashBalances::class)->assertOk()->assertSee($this->location->name)->assertSee($this->branchBox->name)->assertSee('1 · 100.00');

    livewire(DailyCashClosePage::class)->assertOk()->assertSee('بانتظار الاستلام')->assertSee('100.00');
});
