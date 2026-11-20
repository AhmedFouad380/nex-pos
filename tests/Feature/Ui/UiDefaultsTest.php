<?php

use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Support\Avatar;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Contracts\HasColor;

beforeEach(fn () => seedBaseData());

it('gives every status an icon next to its colour', function () {
    foreach (glob(app_path('Domain/*/Enums/*.php')) as $file) {
        $class = 'App\\Domain\\'.str_replace(['/', '.php'], ['\\', ''], str($file)->after('Domain/'));
        if (is_subclass_of($class, HasColor::class)) {
            foreach ($class::cases() as $case) {
                expect($case->getIcon())->toStartWith('heroicon-m-');
            }
        }
    }
    expect(PaymentStatus::Paid->getIcon())->toBe('heroicon-m-check-circle');
});

it('draws a stable coloured initial for names', function () {
    $html = (string) Avatar::html('الأهرام للتجارة');
    expect($html)->toContain('>أ<')->toContain('الأهرام للتجارة')
        ->and((string) Avatar::html('الأهرام للتجارة'))->toBe($html)
        ->and((string) Avatar::html(null))->toBe('—');
});

it('adds icons to phone / email fields, the currency to money fields and icons to known sections', function () {
    expect(TextInput::make('mobile')->getPrefixIcon())->toBe('heroicon-m-device-phone-mobile')
        ->and(TextInput::make('general.email')->getPrefixIcon())->toBe('heroicon-m-envelope')
        ->and(TextInput::make('amount')->getSuffixLabel())->toBe('ج.م')
        ->and(TextInput::make('name')->getPrefixIcon())->toBeNull()
        ->and(Section::make(__('Payments'))->getIcon())->toBe('heroicon-o-banknotes');

    $this->actingAs(User::query()->firstOrFail());
    makeCustomer(['name' => 'محمود']);
    $this->get('/admin/customers')->assertOk()->assertSee('>م<', false);
});
