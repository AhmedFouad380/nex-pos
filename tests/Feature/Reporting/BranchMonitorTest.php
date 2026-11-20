<?php

use App\Domain\Foundation\Models\Location;
use App\Domain\Reporting\Services\BranchSalesMonitor;
use App\Domain\Sales\Models\Sale;
use App\Filament\Admin\Widgets\BranchPerformance;
use App\Models\User;
use App\Settings\PosSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->admin = User::query()->where('email', config('newpos.seed_admin.email'))->firstOrFail();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    $this->tea->product->locations()->attach($this->location->id);
    makePurchase(makeSupplier(), [[$this->tea, 500, 6]]);
    $this->session = openSession($this->admin);
    // a sale of `qty` × 10, moved to the given moment
    $this->saleAt = function (CarbonImmutable $at, int $qty) {
        $sale = sell([[$this->tea, $qty]], [cash($qty * 10)], ['cashSessionId' => $this->session->id]);
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => $at]);

        return $sale;
    };
});

it('compares today with the same weekday of the past weeks, up to the same hour', function () {
    $now = CarbonImmutable::parse('2026-11-19 14:00');
    $this->session->forceFill(['opened_at' => $now->setTime(9, 0)])->save();
    ($this->saleAt)($now->subWeek()->setTime(11, 0), 10);   // 100 by 2 pm a week ago
    ($this->saleAt)($now->subWeek()->setTime(18, 0), 50);   // later that day: not counted
    ($this->saleAt)($now->subWeeks(2)->setTime(10, 0), 30); // 300 two weeks ago
    ($this->saleAt)($now->setTime(13, 30), 5);              // 50 today so far

    $row = collect(app(BranchSalesMonitor::class)->today([$this->location->id], $now))->sole();
    expect($row['today'])->toEqual(50.0)->and($row['normal'])->toEqual(200.0)->and($row['percent'])->toBe(25)
        ->and($row['shift_open'])->toBeTrue()->and($row['idle_hours'])->toEqual(0.5);
});

it('alerts the managers once when a branch with an open shift stops selling', function () {
    app(PosSettings::class)->fill(['idle_alert_hours' => 2])->save();
    $this->session->forceFill(['opened_at' => now()->subHours(5)])->save(); // idle time counts from the last sale or the shift opening
    ($this->saleAt)(CarbonImmutable::now()->subHours(3), 1);

    $this->artisan('sales:idle-alert')->assertSuccessful();
    $this->artisan('sales:idle-alert')->assertSuccessful(); // the same quiet spell: no second alert

    $notices = $this->admin->notifications()->get();
    expect($notices)->toHaveCount(1)->and($notices->first()->data['title'])->toContain($this->location->name);

    // no alert when the setting is off, or the branch has no open shift
    app(PosSettings::class)->fill(['idle_alert_hours' => 0])->save();
    $this->artisan('sales:idle-alert')->assertSuccessful();
    expect($this->admin->notifications()->count())->toBe(1);
});

it('shows the branch performance card on the dashboard', function () {
    ($this->saleAt)(CarbonImmutable::now()->subMinutes(10), 2);

    livewire(BranchPerformance::class)->assertOk()->assertSee($this->location->name)->assertSee('20.00');
});
