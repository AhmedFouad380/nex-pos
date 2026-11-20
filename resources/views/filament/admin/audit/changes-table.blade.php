{{-- Changed fields of one activity: field / before / after --}}
@if (count($rows))
    @php($hasOld = collect($rows)->contains(fn ($row) => $row['old'] !== ''))
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                <tr>
                    <th class="px-3 py-2 text-start font-medium">{{ __('Field') }}</th>
                    @if ($hasOld)
                        <th class="px-3 py-2 text-start font-medium">{{ __('Before') }}</th>
                    @endif
                    <th class="px-3 py-2 text-start font-medium">{{ $hasOld ? __('After') : __('Value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $row)
                    <tr>
                        <td class="px-3 py-1.5 font-medium text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                        @if ($hasOld)
                            <td class="px-3 py-1.5 text-danger-600 dark:text-danger-400 break-all">{{ $row['old'] }}</td>
                        @endif
                        <td class="px-3 py-1.5 {{ $hasOld ? 'text-success-600 dark:text-success-400' : '' }} break-all">{{ $row['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
