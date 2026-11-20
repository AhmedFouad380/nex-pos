{{-- Admin top bar: jump to the cashier screen (shown only to users who can sell). --}}
@if (auth()->user()?->can('pos.sell'))
    <x-filament::button
        tag="a"
        :href="\App\Filament\Pos\Pages\PointOfSale::getUrl(panel: 'pos')"
        icon="heroicon-o-shopping-bag"
        size="sm"
        class="me-2"
    >
        {{ __('Point of sale') }}
    </x-filament::button>
@endif
