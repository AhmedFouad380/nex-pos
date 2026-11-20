@php
    $tables = $this->tables();
    $cards = $this->cards();
    $fmt = fn ($v, $t) => \App\Filament\Admin\Pages\Reports\ReportPage::format($v, $t);
@endphp

<x-filament-panels::page>
    @include('filament.admin.partials.report-styles')
    <style>
        .rp-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0.75rem; }
        .rp-card { border: 1px solid rgb(127 127 127 / 0.25); border-radius: 0.75rem; padding: 0.75rem 1rem; }
        .rp-card .label { font-size: 0.8rem; opacity: 0.7; }
        .rp-card .value { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .rp-card .value.success { color: #16a34a; } .rp-card .value.danger { color: #dc2626; }
        .rp-print-head { display: none; }
        @media print { .rp-print-head { display: block; margin-bottom: 0.5rem; } }
    </style>

    <div class="rp-filters">
        @if ($this->usesPeriod())
            <label><span>{{ __('From') }}</span><input type="date" wire:model.live="from"></label>
            <label><span>{{ __('To') }}</span><input type="date" wire:model.live="to"></label>
        @endif
        @if ($this->usesLocation() && count($this->locationOptions()) > 1)
            <label><span>{{ __('Location') }}</span>
                <select wire:model.live="location">
                    <option value="">{{ __('All locations') }}</option>
                    @foreach ($this->locationOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select></label>
        @endif
        @if ($this->groupOptions())
            <label><span>{{ __('Group by') }}</span>
                <select wire:model.live="group">
                    @foreach ($this->groupOptions() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select></label>
        @endif
        @if ($this->optionChoices())
            <label><span>{{ $this->optionLabel() }}</span>
                <select wire:model.live="option">
                    @foreach ($this->optionChoices() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select></label>
        @endif
        @if ($this->flagLabel())
            <label class="check"><input type="checkbox" wire:model.live="flag"> {{ $this->flagLabel() }}</label>
        @endif
    </div>

    <div class="rp-print-head">
        <strong>{{ $this->getTitle() }}</strong>
        @if ($this->usesPeriod())<span class="rp-muted"> — {{ $from }} → {{ $to }}</span>@endif
    </div>

    @if ($cards)
        <div class="rp-cards">
            @foreach ($cards as $card)
                <div class="rp-card">
                    <div class="label">{{ $card[0] }}</div>
                    <div class="value {{ $card[3] ?? '' }}">{{ $fmt($card[1], $card[2] ?? 'money') ?: '—' }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @foreach ($tables as $t => $table)
        <x-filament::section wire:key="table-{{ $t }}" :heading="$table['title'] ?? null">
            <div style="overflow-x: auto">
                <table class="rp-table">
                    <thead><tr>
                        @foreach ($table['columns'] as $column)
                            <th @class(['num' => ($column[1] ?? 'text') !== 'text'])>{{ $column[0] }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse ($table['rows'] as $row)
                            <tr @class(['group' => $row['_heading'] ?? false])>
                                @foreach ($table['columns'] as $key => $column)
                                    @php($type = $column[1] ?? 'text')
                                    @php($indent = $loop->first && isset($row['_depth']) ? 'padding-inline-start: '.(0.4 + $row['_depth'] * 1.2).'rem' : '')
                                    <td @class(['num' => $type !== 'text']) style="{{ $indent }}">
                                        @if ($loop->first && isset($row['_url']))
                                            <a href="{{ $row['_url'] }}">{{ $fmt($row[$key] ?? null, $type) }}</a>
                                        @else
                                            {{ $fmt($row[$key] ?? null, $type) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($table['columns']) }}" class="rp-muted" style="text-align: center; padding: 1.5rem">{{ __('No data for this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if (! empty($table['totals']) && $table['rows'])
                        <tfoot><tr>
                            @foreach ($table['columns'] as $key => $column)
                                <td @class(['num' => ($column[1] ?? 'text') !== 'text'])>{{ $fmt($table['totals'][$key] ?? null, $column[1] ?? 'text') }}</td>
                            @endforeach
                        </tr></tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
