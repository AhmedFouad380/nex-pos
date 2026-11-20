@php($rows = $this->rows())
@php($limit = $this->idleLimit())
<x-filament-widgets::widget>
    <x-filament::section :heading="__('Branch performance today')" :description="__('Compared with the same weekday over the last 8 weeks, up to this hour.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 text-start font-medium">{{ __('Location') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Today so far') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Usual by now') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Of usual') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Last sale') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($rows as $row)
                        @php($idle = $row['shift_open'] && $limit > 0 && ($row['idle_hours'] ?? 0) >= $limit)
                        <tr>
                            <td class="py-2 font-semibold">{{ $row['name'] }}</td>
                            <td class="py-2 tabular-nums">{{ number_format($row['today'], 2) }} <span class="text-xs text-gray-500">({{ $row['invoices'] }})</span></td>
                            <td class="py-2 tabular-nums text-gray-500">{{ $row['normal'] !== null ? number_format($row['normal'], 2) : '—' }}</td>
                            <td class="py-2">
                                @if (! $row['shift_open'] && $row['invoices'] === 0)
                                    <x-filament::badge color="gray">{{ __('Not started yet') }}</x-filament::badge>
                                @elseif ($row['percent'] !== null)
                                    <x-filament::badge :color="\App\Filament\Admin\Widgets\BranchPerformance::tone($row['percent'])">{{ $row['percent'] }}%</x-filament::badge>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($idle)
                                    <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle">{{ __('No sales for :hours h', ['hours' => $row['idle_hours']]) }}</x-filament::badge>
                                @elseif ($row['last_sale_at'])
                                    <span class="text-gray-500" title="{{ $row['last_sale_at']->format('Y-m-d H:i') }}">{{ $row['last_sale_at']->diffForHumans() }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                                @unless ($row['shift_open'])
                                    <span class="text-xs text-gray-400">· {{ __('No open shift') }}</span>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500">{{ __('Nothing to show.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
