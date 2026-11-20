<x-filament-panels::page>
    @php
        $steps = $this->steps();
        $required = collect($steps)->where('required', true);
        $done = $required->where('done', true)->count();
    @endphp

    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-base font-semibold">{{ __('onboarding.intro.title') }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('onboarding.intro.description') }}</p>
            </div>
            <x-filament::badge :color="$done === $required->count() ? 'success' : 'warning'" size="lg">
                {{ __('onboarding.progress', ['done' => $done, 'total' => $required->count()]) }}
            </x-filament::badge>
        </div>
    </x-filament::section>

    @foreach (['required' => true, 'optional' => false] as $group => $isRequired)
        <x-filament::section :heading="__('onboarding.groups.'.$group)">
            <ol class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach (collect($steps)->where('required', $isRequired)->values() as $i => $step)
                    <li class="flex items-start gap-3 py-3">
                        <x-filament::icon
                            :icon="$step['done'] ? 'heroicon-s-check-circle' : 'heroicon-o-ellipsis-horizontal-circle'"
                            @class(['h-6 w-6 shrink-0', 'text-success-600' => $step['done'], 'text-gray-400' => ! $step['done']])
                        />
                        <div class="min-w-0 flex-1">
                            <p @class(['font-medium', 'text-gray-500 line-through' => $step['done']])>{{ $step['title'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $step['description'] }}</p>
                        </div>
                        @if ($step['url'])
                            <x-filament::button tag="a" :href="$step['url']" size="sm" :color="$step['done'] ? 'gray' : 'primary'" :outlined="$step['done']">
                                {{ $step['done'] ? __('onboarding.review') : $step['action'] }}
                            </x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
