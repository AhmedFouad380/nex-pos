@php
    $ledger = $this->ledger();
    $account = $this->account();
    $sign = $account->type->isDebitNormal() ? 1 : -1;
    $fmt = fn ($v) => number_format((float) $v * $sign, 2);
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
            <div class="name">{{ $account->code }} — {{ $account->name }}</div>
            <div class="rp-muted">{{ $account->type->getLabel() }}@if ($account->sub_type) · {{ $account->sub_type->getLabel() }}@endif</div>
            <div class="rp-muted">{{ __('Period') }}: {{ $from ?: '—' }} → {{ $to ?: '—' }}</div>
        </div>
        <div style="overflow-x: auto">
            <table class="rp-table">
                <thead>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Description') }}</th><th>{{ __('Contact') }}</th>
                        <th class="num">{{ __('Debit') }}</th><th class="num">{{ __('Credit') }}</th><th class="num">{{ __('Balance') }}</th></tr>
                </thead>
                <tbody>
                    <tr class="carried"><td colspan="6">{{ __('Balance carried forward') }}</td><td class="num">{{ $fmt($ledger['opening']) }}</td></tr>
                    @forelse ($ledger['rows'] as $row)
                        <tr wire:key="l-{{ $loop->index }}">
                            <td style="white-space: nowrap">{{ \Illuminate\Support\Carbon::parse($row->entry_date)->format('Y-m-d') }}</td>
                            <td><a href="{{ $this->entryUrl((int) $row->entry_id) }}">{{ $row->number }}</a></td>
                            <td>{{ $row->description }}@if ($row->memo) <span class="rp-muted">— {{ $row->memo }}</span>@endif</td>
                            <td>{{ $row->contact }}</td>
                            <td class="num">{{ $plain($row->debit) }}</td>
                            <td class="num">{{ $plain($row->credit) }}</td>
                            <td class="num">{{ $fmt($row->balance) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="rp-muted" style="text-align: center; padding: 1.5rem">{{ __('No movements in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><td colspan="4">{{ __('Total') }}</td><td class="num">{{ number_format((float) $ledger['debit'], 2) }}</td>
                        <td class="num">{{ number_format((float) $ledger['credit'], 2) }}</td><td class="num">{{ $fmt($ledger['closing']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
