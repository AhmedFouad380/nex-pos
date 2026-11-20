{{-- History of one record (ActivityHistoryAction) --}}
<div class="space-y-4">
    @forelse ($activities as $activity)
        <div class="space-y-2 border-b border-gray-200 pb-4 last:border-0 dark:border-white/10">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <x-filament::badge :color="\App\Support\Audit\ActivityPresenter::eventColor($activity->event)">
                    {{ \App\Support\Audit\ActivityPresenter::eventLabel($activity->event) }}
                </x-filament::badge>
                @if ($activity->log_name !== $mainLog)
                    <span class="text-gray-500">{{ \App\Support\Audit\ActivityPresenter::subjectLabel($activity->log_name) }}</span>
                @endif
                <span class="font-medium">{{ \App\Support\Audit\ActivityPresenter::causerName($activity) }}</span>
                <span class="text-gray-500" title="{{ $activity->created_at?->diffForHumans() }}">{{ $activity->created_at?->format('Y-m-d H:i') }}</span>
            </div>
            @if ($activity->event !== 'created')
                @include('filament.admin.audit.changes-table', ['rows' => \App\Support\Audit\ActivityPresenter::changes($activity, resolveRelations: true)])
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('Nothing recorded yet.') }}</p>
    @endforelse
</div>
