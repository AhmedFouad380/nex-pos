@php
    $statement = $this->statement();
    $contact = $this->contact();
    $fmt = fn ($v) => number_format((float) $v, 2);
    $side = fn ($v) => (float) $v > 0 ? __('They owe us') : ((float) $v < 0 ? __('We owe them') : __('Settled'));
@endphp

<x-filament-panels::page>
    <style>
        .st-filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
        .st-filters label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
        .st-filters input { border: 1px solid rgb(127 127 127 / 0.35); border-radius: 0.5rem; padding: 0.4rem 0.6rem; background: transparent; color: inherit; }
        .st-head { margin-bottom: 1rem; display: grid; gap: 0.2rem; font-size: 0.9rem; }
        .st-head .name { font-size: 1.1rem; font-weight: 700; }
        .st-muted { opacity: 0.65; }
        .st-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .st-table th, .st-table td { padding: 0.45rem 0.4rem; border-bottom: 1px solid rgb(127 127 127 / 0.2); text-align: start; }
        .st-table .num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .st-table .carried td { font-weight: 600; background: rgb(127 127 127 / 0.08); }
        .st-table tfoot td { font-weight: 700; border-top: 2px solid rgb(127 127 127 / 0.4); }
        .st-table a { color: var(--primary-600); }
        @media print { .st-filters { display: none; } }
    </style>

    <div class="st-filters">
        <label><span>{{ __('From') }}</span><input type="date" wire:model.live="from"></label>
        <label><span>{{ __('To') }}</span><input type="date" wire:model.live="to"></label>
    </div>

    <x-filament::section>
        <div class="st-head">
            <div class="name">{{ $contact->name }} <span class="st-muted">({{ $contact->code }})</span></div>
            @if ($contact->mobile)<div>{{ $contact->mobile }}</div>@endif
            <div class="st-muted">{{ __('Period') }}: {{ $from ?: '—' }} → {{ $to ?: '—' }}</div>
        </div>

        <div style="overflow-x: auto">
            <table class="st-table">
                <thead>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Document') }}</th><th>{{ __('Number') }}</th>
                        <th class="num">{{ __('Debit') }}</th><th class="num">{{ __('Credit') }}</th><th class="num">{{ __('Balance') }}</th></tr>
                </thead>
                <tbody>
                    <tr class="carried"><td colspan="5">{{ __('Balance carried forward') }}</td><td class="num">{{ $fmt($statement['opening']) }}</td></tr>
                    @forelse ($statement['rows'] as $row)
                        @php($url = $this->documentUrl($row->kind, (int) $row->ref_id))
                        <tr wire:key="row-{{ $row->kind }}-{{ $row->ref_id }}">
                            <td style="white-space: nowrap">{{ \Illuminate\Support\Carbon::parse($row->entry_date)->format('Y-m-d') }}</td>
                            <td>{{ __('statement.kinds.'.$row->kind) }}</td>
                            <td>@if ($url)<a href="{{ $url }}">{{ $row->number }}</a>@else{{ $row->number }}@endif</td>
                            <td class="num">{{ (float) $row->debit ? $fmt($row->debit) : '' }}</td>
                            <td class="num">{{ (float) $row->credit ? $fmt($row->credit) : '' }}</td>
                            <td class="num">{{ $fmt($row->balance) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="st-muted" style="text-align: center; padding: 1.5rem">{{ __('No movements in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><td colspan="5">{{ __('Closing balance') }} — {{ $side($statement['closing']) }}</td><td class="num">{{ $fmt(abs((float) $statement['closing'])) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
