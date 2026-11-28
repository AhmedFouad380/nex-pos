@php($data = $this->data())
@php($shifts = \App\Filament\Admin\Resources\CashSessions\CashSessionResource::class)
@php($boxes = \App\Filament\Admin\Resources\Treasuries\TreasuryResource::class)
<x-filament-widgets::widget>
    <x-filament::section :heading="__('Branch cash')" :description="__('The cash box of each branch as the books have it now, the shift open there and the closed shifts whose cash is not received yet.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 text-start font-medium">{{ __('Location') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Cash box') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Shift open now') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('Awaiting receipt') }}</th>
                        <th class="py-2 text-end font-medium">{{ __('Box balance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($data['rows'] as $row)
                        <tr>
                            <td class="py-2 font-semibold">{{ $row['location']->name }}</td>
                            <td class="py-2">
                                @if ($row['box'])
                                    <a class="text-primary-600 hover:underline" href="{{ $boxes::getUrl('statement', ['record' => $row['box']]) }}">{{ $row['box']->name }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($row['shift'])
                                    <a class="hover:underline" href="{{ $shifts::getUrl('view', ['record' => $row['shift']]) }}">{{ $row['shift']->user->name }}</a>
                                    <span class="text-xs text-gray-500">· {{ __('Expected in drawer') }} {{ number_format($row['expected'], 2) }}</span>
                                @else
                                    <span class="text-gray-400">{{ __('No open shift') }}</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($row['pending'] > 0)
                                    <a href="{{ $shifts::getUrl('index', ['tableFilters' => ['awaiting_receipt' => ['isActive' => true]]]) }}">
                                        <x-filament::badge color="warning">{{ $row['pending'] }} · {{ number_format($row['pending_cash'], 2) }}</x-filament::badge>
                                    </a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-2 text-end font-semibold tabular-nums {{ $row['balance'] < 0 ? 'text-danger-600' : '' }}">{{ number_format($row['balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500">{{ __('Nothing to show.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-300 font-bold dark:border-white/20">
                        <td class="py-2" colspan="3">{{ __('Total') }}</td>
                        <td class="py-2">@if ($data['pending'] > 0){{ $data['pending'] }} · {{ number_format($data['pending_cash'], 2) }}@endif</td>
                        <td class="py-2 text-end tabular-nums">{{ number_format($data['balance'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
