@php($items = $this->items())
<x-filament-widgets::widget>
    <x-filament::section :heading="$this->heading()">
        <style>
            .dw-list { display: flex; flex-direction: column; }
            .dw-row { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.45rem 0; border-bottom: 1px solid rgb(127 127 127 / 0.15); font-size: 0.9rem; }
            .dw-row:last-child { border-bottom: 0; }
            .dw-hint { opacity: 0.6; font-size: 0.8rem; }
            .dw-value { font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }
            .dw-value.danger { color: #dc2626; }
            .dw-more { display: inline-block; margin-top: 0.5rem; font-size: 0.85rem; color: var(--primary-600); }
        </style>
        <div class="dw-list">
            @forelse ($items as $item)
                <div class="dw-row">
                    <span>{{ $item['label'] }} @if (! empty($item['hint']))<span class="dw-hint">{{ $item['hint'] }}</span>@endif</span>
                    <span @class(['dw-value', 'danger' => $item['danger'] ?? false])>{{ $item['value'] }}</span>
                </div>
            @empty
                <div class="dw-hint" style="padding: 1rem 0; text-align: center">{{ $this->emptyText() }}</div>
            @endforelse
        </div>
        @if ($url = $this->moreUrl())<a class="dw-more" href="{{ $url }}">{{ __('Full report') }} ←</a>@endif
    </x-filament::section>
</x-filament-widgets::widget>
