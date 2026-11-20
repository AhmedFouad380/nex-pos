{{-- "Help" in the cash register screen: resources/help/ar/pos.md (App\Support\Help\HelpTopics) --}}
@if ($help)
    <x-filament::modal slide-over width="xl" id="pos-help">
        <x-slot name="trigger">
            <button type="button" class="pos-tool" title="{{ __('Help') }}">@svg('heroicon-o-question-mark-circle')<span>{{ __('Help') }}</span></button>
        </x-slot>
        <x-slot name="heading">{{ $help['title'] }}</x-slot>
        <div class="fi-prose">{{ $help['html'] }}</div>
    </x-filament::modal>
@endif
