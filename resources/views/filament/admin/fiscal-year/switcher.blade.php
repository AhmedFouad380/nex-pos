{{-- Top bar: the fiscal year every list and report shows (App\Support\FiscalYear). --}}
@php($years = \App\Support\FiscalYear::years())
<form method="POST" action="{{ route('fiscal-year.switch') }}" class="me-2" x-data>
    @csrf
    <x-filament::input.wrapper :prefix-icon="'heroicon-o-calendar-days'" @class(['ring-warning-500' => ! \App\Support\FiscalYear::isCurrent()]) title="{{ __('Fiscal year') }}">
        <x-filament::input.select name="year" x-on:change="$el.form.submit()" aria-label="{{ __('Fiscal year') }}">
            @foreach ($years as $year => $label)
                <option value="{{ $year }}" @selected($year === \App\Support\FiscalYear::selected())>{{ __('Year :year', ['year' => $label]) }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</form>
