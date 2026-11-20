{{-- The "?" in the page header: this page's help in a side panel (App\Support\Help\HelpTopics). --}}
<x-filament::modal slide-over width="xl" id="page-help" :close-by-clicking-away="true">
    <x-slot name="trigger">
        <x-filament::icon-button
            icon="heroicon-o-question-mark-circle"
            size="lg"
            color="gray"
            :label="__('Help')"
            :tooltip="__('Help for this page')"
        />
    </x-slot>

    <x-slot name="heading">
        {{ $topic['title'] }}
    </x-slot>

    <div class="fi-prose">
        {{ $topic['html'] }}
    </div>
</x-filament::modal>
