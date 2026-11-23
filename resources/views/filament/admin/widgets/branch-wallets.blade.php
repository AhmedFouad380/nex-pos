@php
    $branches = $this->branches();
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $colors = ['success' => '#16a34a', 'warning' => '#f59e0b', 'danger' => '#dc2626'];
    $warn = \App\Domain\Wallet\Services\WalletUsage::WARN_PERCENT;
    $muted = 'color: rgb(107 114 128); font-size: .75rem';
@endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('Branch wallets')" :description="__('Balance as the phone last reported it, the transfers of today and of this month against the limits of each line, and what it can still send.')">
        @foreach ($branches as $branch => $data)
            <div style="margin-bottom: 1.5rem">
                <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: .5rem; margin-bottom: .5rem">
                    <strong style="font-size: 1rem">{{ $branch }}</strong>
                    <span style="{{ $muted }}; font-size: .875rem">
                        {{ __('Balance') }}: <strong>{{ $money($data['balance']) }}</strong>
                        &nbsp;·&nbsp; {{ __('Available to send') }}: <strong style="color: {{ $colors['success'] }}">{{ $money($data['available']) }}</strong>
                    </span>
                </div>
                <div style="overflow-x: auto">
                    <table style="width: 100%; font-size: .875rem; border-collapse: collapse">
                        <thead>
                        <tr style="{{ $muted }}; text-align: start">
                            @foreach ([__('Line'), __('Balance'), __('Sent today'), __('Sent this month'), __('Received today'), __('Available to send')] as $heading)
                                <th style="text-align: start; font-weight: 500; padding: .4rem .5rem; border-bottom: 1px solid rgb(229 231 235 / .6)">{{ $heading }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($data['lines'] as ['wallet' => $wallet, 'usage' => $usage])
                            <tr style="border-bottom: 1px solid rgb(229 231 235 / .35)">
                                <td style="padding: .5rem">
                                    <div style="font-weight: 600">{{ $wallet->name }}</div>
                                    <div style="{{ $muted }}"><span dir="ltr">{{ $wallet->phone }}</span>@if ($wallet->sim_slot) · SIM {{ $wallet->sim_slot }}@endif</div>
                                </td>
                                <td style="padding: .5rem">
                                    {{ $money($wallet->last_balance) }}
                                    <div style="{{ $muted }}">{{ $wallet->last_balance_at?->diffForHumans() }}</div>
                                </td>
                                @foreach ([[$usage['out_today'], $wallet->daily_out_limit], [$usage['out_month'], $wallet->monthly_out_limit]] as [$used, $limit])
                                    <td style="padding: .5rem; min-width: 9rem">
                                        {{ $money($used) }}
                                        @if ($limit !== null && (float) $limit > 0)
                                            @php($p = (int) round($used / (float) $limit * 100))
                                            <span style="{{ $muted }}">{{ __('of') }} {{ $money($limit) }}</span>
                                            <div style="height: 6px; margin-top: 4px; border-radius: 9999px; background: rgb(156 163 175 / .25)">
                                                <div style="height: 6px; border-radius: 9999px; width: {{ min(100, $p) }}%; background: {{ $p >= 100 ? $colors['danger'] : ($p >= $warn ? $colors['warning'] : $colors['success']) }}"></div>
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                                <td style="padding: .5rem">
                                    {{ $money($usage['in_today']) }}
                                    @if ($wallet->daily_in_limit !== null)<span style="{{ $muted }}">{{ __('of') }} {{ $money($wallet->daily_in_limit) }}</span>@endif
                                </td>
                                <td style="padding: .5rem">
                                    <x-filament::badge :color="$usage['level']">{{ $money($usage['available_out']) }}</x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </x-filament::section>
</x-filament-widgets::widget>
