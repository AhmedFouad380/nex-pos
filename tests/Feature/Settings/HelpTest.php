<?php

use App\Filament\Admin\Pages\GettingStarted;
use App\Filament\Admin\Resources\PayrollRuns\Pages\CreatePayrollRun;
use App\Filament\Admin\Resources\Sales\Pages\ListSales;
use App\Filament\Admin\Resources\Sales\Pages\ViewSale;
use App\Filament\Pos\Pages\PointOfSale;
use App\Models\User;
use App\Support\Help\HelpTopics;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
});

it('finds the help of a page, falling back to its resource', function () {
    expect(HelpTopics::keyFor(ListSales::class))->toBe('sales.index')
        ->and(HelpTopics::forPage(ViewSale::class)['key'])->toBe('sales')
        ->and(HelpTopics::forPage(CreatePayrollRun::class)['title'])->toBe('مسيرات الرواتب')
        ->and(HelpTopics::find('../../.env'))->toBeNull();
});

it('has help for every page, a page for every help file and a title in every file', function () {
    $pages = collect(['admin', 'pos'])->flatMap(fn ($panel) => collect(Filament::getPanel($panel)->getPages())
        ->concat(collect(Filament::getPanel($panel)->getResources())->flatMap(fn ($r) => collect($r::getPages())->map->getPage()->values())));
    $keys = $pages->map(fn ($page) => HelpTopics::keyFor($page))->filter()
        ->flatMap(fn ($key) => [$key, str($key)->beforeLast('.')->toString()])->unique();

    foreach (HelpTopics::all() as $file) {
        expect($keys)->toContain($file);
        expect(HelpTopics::find($file)['title'])->not->toBe('');
    }
    // every page of the sidebar and every resource list must have help
    $entry = collect(['admin', 'pos'])->flatMap(fn ($panel) => collect(Filament::getPanel($panel)->getPages())
        ->concat(collect(Filament::getPanel($panel)->getResources())->map(fn ($r) => $r::getPages()['index']->getPage())));
    $missing = $entry->filter(fn ($page) => HelpTopics::forPage($page) === null)->values()->all();
    expect($missing)->toBe([]);
});

it('shows the help in the cash register screen', function () {
    expect(HelpTopics::keyFor(PointOfSale::class))->toBe('pos.sell');
    $this->get(PointOfSale::getUrl(panel: 'pos'))->assertOk()->assertSee('إيقاف الفاتورة مؤقتًا');
});

it('shows the help button in the page header', function () {
    $this->get(ListSales::getUrl())->assertOk()->assertSee(__('Help for this page'))->assertSee('تحصيل دفعة');
});

it('ticks the setup steps from the data', function () {
    $steps = collect(GettingStarted::steps())->keyBy('key');
    expect($steps['products']['done'])->toBeFalse();

    makeVariation();

    expect(collect(GettingStarted::steps())->keyBy('key')['products']['done'])->toBeTrue();
    livewire(GettingStarted::class)->assertOk()->assertSee(__('onboarding.steps.products.title'));
});

it('builds the printable guide from existing help files', function () {
    foreach (require resource_path('help/guide.php') as $part) {
        foreach ($part['topics'] as $key) {
            expect(HelpTopics::find($key))->not->toBeNull("guide topic {$key} has no help file");
        }
    }

    $this->get(route('guide.print'))->assertOk()->assertSee('دليل استخدام النظام')->assertSee('مسيرات الرواتب');
});

it('refuses to build the guide on the client database', function () {
    config(['newpos.guide_database' => config('database.connections.'.config('database.default').'.database')]);

    $this->artisan('guide:build')->assertFailed();
});
