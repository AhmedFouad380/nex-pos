@php
    $report = $this->report();
    $n = fn ($v) => (float) $v ? number_format((float) $v, 2) : '';
    $dr = fn ($v) => (float) $v > 0 ? number_format((float) $v, 2) : '';
    $cr = fn ($v) => (float) $v < 0 ? number_format(-(float) $v, 2) : '';
@endphp

<x-filament-panels::page>
    @include('filament.admin.partials.report-styles')

    <div class="rp-filters">
        <label><span>{{ __('From') }}</span><input type="date" wire:model.live="from"></label>
        <label><span>{{ __('To') }}</span><input type="date" wire:model.live="to"></label>
        <label class="check"><input type="checkbox" wire:model.live="hideZero"> {{ __('Hide accounts without balance') }}</label>
        <label class="check"><input type="checkbox" wire:model.live="leavesOnly"> {{ __('Sub-accounts only') }}</label>
    </div>

    <x-filament::section>
        <div class="rp-head">
            <div class="name">{{ __('Trial balance') }}</div>
            <div class="rp-muted">{{ __('Period') }}: {{ $from ?: '—' }} → {{ $to ?: '—' }}</div>
            <div class="{{ $report['balanced'] ? 'rp-ok' : 'rp-bad' }}">{{ $report['balanced'] ? __('Balanced') : __('Not balanced') }}</div>
        </div>
        <div style="overflow-x: auto">
            <table class="rp-table">
                <thead>
                    <tr><th rowspan="2">{{ __('Code') }}</th><th rowspan="2">{{ __('Account') }}</th>
                        <th class="num" colspan="2" style="text-align: center">{{ __('Opening balance') }}</th>
                        <th class="num" colspan="2" style="text-align: center">{{ __('Period movement') }}</th>
                        <th class="num" colspan="2" style="text-align: center">{{ __('Closing balance') }}</th></tr>
                    <tr><th class="num">{{ __('Debit') }}</th><th class="num">{{ __('Credit') }}</th><th class="num">{{ __('Debit') }}</th>
                        <th class="num">{{ __('Credit') }}</th><th class="num">{{ __('Debit') }}</th><th class="num">{{ __('Credit') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        @php($a = $row['account'])
                        <tr wire:key="tb-{{ $a->id }}" @class(['group' => ! $a->is_postable])>
                            <td style="font-family: monospace">{{ $a->code }}</td>
                            <td style="padding-inline-start: {{ 0.4 + ($leavesOnly ? 0 : $row['depth']) * 1.2 }}rem">
                                <a href="{{ $this->ledgerUrl($a->id) }}">{{ $a->name }}</a></td>
                            <td class="num">{{ $dr($row['opening']) }}</td><td class="num">{{ $cr($row['opening']) }}</td>
                            <td class="num">{{ $n($row['debit']) }}</td><td class="num">{{ $n($row['credit']) }}</td>
                            <td class="num">{{ $dr($row['closing']) }}</td><td class="num">{{ $cr($row['closing']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="rp-muted" style="text-align: center; padding: 1.5rem">{{ __('No movements in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><td colspan="2">{{ __('Total') }}</td>
                        <td class="num">{{ number_format((float) $report['totals']['opening_dr'], 2) }}</td><td class="num">{{ number_format((float) $report['totals']['opening_cr'], 2) }}</td>
                        <td class="num">{{ number_format((float) $report['totals']['debit'], 2) }}</td><td class="num">{{ number_format((float) $report['totals']['credit'], 2) }}</td>
                        <td class="num">{{ number_format((float) $report['totals']['closing_dr'], 2) }}</td><td class="num">{{ number_format((float) $report['totals']['closing_cr'], 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
