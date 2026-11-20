{{-- Shown on every page while an older fiscal year is selected. --}}
@unless (\App\Support\FiscalYear::isCurrent())
    @php($year = \App\Support\FiscalYear::selected())
    <div class="fi-fiscal-banner flex flex-wrap items-center justify-between gap-2 rounded-xl border border-warning-300 bg-warning-50 px-4 py-2 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-300">
        <span>{{ __('You are viewing the year :year. Lists and reports show this year only.', ['year' => \App\Support\FiscalYear::label($year)]) }}</span>
        <form method="POST" action="{{ route('fiscal-year.switch') }}">
            @csrf
            <input type="hidden" name="year" value="{{ \App\Support\FiscalYear::current() }}">
            <x-filament::button type="submit" size="xs" color="warning">{{ __('Back to the current year') }}</x-filament::button>
        </form>
    </div>
@endunless
