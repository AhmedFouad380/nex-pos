@php
    $st = $this->statement();
    $box = $this->box();
    $money = fn ($v) => number_format((float) $v, 2);
    $plain = fn ($v) => (float) $v ? number_format((float) $v, 2) : '';
@endphp

<x-filament-panels::page>
    @include('filament.admin.partials.report-styles')

    <div class="rp-filters">
        <label><span>{{ __('From') }}</span><input type="date" wire:model.live="from"></label>
        <label><span>{{ __('To') }}</span><input type="date" wire:model.live="to"></label>
    </div>

    <x-filament::section>
        <div class="rp-head">
            <div class="name">{{ $box->code }} — {{ $box->name }}</div>
            <div class="rp-muted">{{ $box->sub_type?->getLabel() }}@if ($branches = \App\Domain\Foundation\Models\Location::query()->whereKey(\App\Filament\Admin\Resources\Treasuries\TreasuryResource::branchesOf($box))->pluck('name')->implode('، ')) · {{ __('Cash box of branch') }}: {{ $branches }}@endif</div>
            <div class="rp-muted">{{ __('Period') }}: {{ $from ?: '—' }} → {{ $to ?: '—' }}</div>
        </div>
        <div style="overflow-x: auto">
            <table class="rp-table">
                <thead>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Number') }}</th><th>{{ __('Party') }}</th><th>{{ __('Description') }}</th>
                        <th class="num">{{ __('Money in') }}</th><th class="num">{{ __('Money out') }}</th><th class="num">{{ __('Balance') }}</th></tr>
                </thead>
                <tbody>
                    <tr class="carried"><td colspan="7">{{ __('Balance carried forward') }}</td><td class="num">{{ $money($st['opening']) }}</td></tr>
                    @forelse ($st['rows'] as $row)
                        <tr wire:key="t-{{ $loop->index }}">
                            <td style="white-space: nowrap">{{ $row['date'] }}</td>
                            <td style="white-space: nowrap">{{ $row['kind'] }}</td>
                            <td style="white-space: nowrap">@if ($row['url'])<a href="{{ $row['url'] }}">{{ $row['number'] }}</a>@else{{ $row['number'] }}@endif</td>
                            <td>{{ $row['party'] }}</td>
                            <td class="rp-muted">{{ $row['description'] }}</td>
                            <td class="num">{{ $plain($row['in']) }}</td>
                            <td class="num">{{ $plain($row['out']) }}</td>
                            <td class="num">{{ $money($row['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="rp-muted" style="text-align: center; padding: 1.5rem">{{ __('No movements in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><td colspan="5">{{ __('Total') }}</td><td class="num">{{ $money($st['in']) }}</td>
                        <td class="num">{{ $money($st['out']) }}</td><td class="num">{{ $money($st['closing']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
