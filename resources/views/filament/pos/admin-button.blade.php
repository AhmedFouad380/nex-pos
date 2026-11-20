{{-- POS top bar: back to the admin dashboard (only for users who may open it). --}}
@if (auth()->user()?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
    <x-filament::button
        tag="a"
        :href="\App\Filament\Admin\Pages\Dashboard::getUrl(panel: 'admin')"
        icon="heroicon-o-squares-2x2"
        size="sm"
        color="gray"
        class="me-2"
    >
        {{ __('Dashboard') }}
    </x-filament::button>
@endif
