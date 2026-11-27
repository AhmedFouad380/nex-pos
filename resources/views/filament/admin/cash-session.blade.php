@php
    $s = $this->session();
    $sum = $this->summary();
    $fmt = fn ($v) => number_format((float) $v, 2);
@endphp
<x-filament-panels::page>
    <style>
        .cs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; }
        .cs-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .cs-table td, .cs-table th { padding: 0.45rem 0.3rem; border-bottom: 1px solid rgb(127 127 127 / 0.2); text-align: start; }
        .cs-table .num { text-align: end; font-variant-numeric: tabular-nums; }
        .cs-strong td { font-weight: 700; }
    </style>
    <div class="cs-grid">
        <x-filament::section :heading="__('Shift')">
            <table class="cs-table">
                <tr><td>{{ __('Location') }}</td><td>{{ $s->cashRegister->location->name }}</td></tr>
                <tr><td>{{ __('Cashier') }}</td><td>{{ $s->user->name }}</td></tr>
                <tr><td>{{ __('Opened at') }}</td><td>{{ $s->opened_at->format('Y-m-d H:i') }}</td></tr>
                <tr><td>{{ __('Closed at') }}</td><td>{{ $s->closed_at?->format('Y-m-d H:i') ?? '—' }} @if ($s->closer) ({{ $s->closer->name }}) @endif</td></tr>
                <tr><td>{{ __('Status') }}</td><td>{{ $s->status->getLabel() }}</td></tr>
                <tr><td>{{ __('Invoices in this shift') }}</td><td>{{ $sum['sales_count'] }} / {{ $fmt($sum['sales_total']) }}</td></tr>
            </table>
        </x-filament::section>
        <x-filament::section :heading="__('Cash summary')">
            <table class="cs-table">
                <tr><td>{{ __('Opening cash') }}</td><td class="num">{{ $fmt($sum['opening']) }}</td></tr>
                <tr><td>{{ __('Cash sales') }}</td><td class="num">{{ $fmt($sum['cash_sales']) }}</td></tr>
                <tr><td>{{ __('Other cash received') }}</td><td class="num">{{ $fmt($sum['cash_received_other']) }}</td></tr>
                <tr><td>{{ __('Cash refunds') }}</td><td class="num">-{{ $fmt($sum['cash_refunds']) }}</td></tr>
                <tr><td>{{ __('Other cash paid out') }}</td><td class="num">-{{ $fmt($sum['cash_paid_other']) }}</td></tr>
                <tr><td>{{ __('Cash in') }}</td><td class="num">{{ $fmt($sum['cash_in']) }}</td></tr>
                <tr><td>{{ __('Cash out') }}</td><td class="num">-{{ $fmt($sum['cash_out']) }}</td></tr>
                <tr class="cs-strong"><td>{{ __('Expected in drawer') }}</td><td class="num">{{ $fmt($sum['expected_cash']) }}</td></tr>
                @if ($s->counted_cash !== null)
                    <tr><td>{{ __('Counted cash') }}</td><td class="num">{{ $fmt($s->counted_cash) }}</td></tr>
                    <tr class="cs-strong"><td>{{ __('Difference') }}</td><td class="num">{{ $fmt($s->difference) }}</td></tr>
                @endif
            </table>
        </x-filament::section>
        <x-filament::section :heading="__('By payment method')">
            <table class="cs-table">
                @forelse ($sum['by_method'] as $m)
                    <tr><td>{{ $m['name'] }}</td><td class="num">{{ $fmt($m['in']) }}</td><td class="num">-{{ $fmt($m['out']) }}</td></tr>
                @empty
                    <tr><td>{{ __('No payments yet.') }}</td></tr>
                @endforelse
            </table>
        </x-filament::section>
    </div>
    @php($operations = $this->operations())
    @if ($operations !== [])
        <x-filament::section :heading="__('Shift operations')" :description="__('Advances, vouchers, expenses and returns paid from or into the branch cash during the shift.')">
            <table class="cs-table">
                <tr><th>{{ __('Time') }}</th><th>{{ __('Number') }}</th><th>{{ __('Type') }}</th><th>{{ __('Party') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('By') }}</th><th class="num">{{ __('Amount') }}</th></tr>
                @foreach ($operations as ['payment' => $p, 'kind' => $kind])
                    <tr>
                        <td>{{ $p->paid_at->format('Y-m-d H:i') }}</td>
                        <td><a href="{{ \App\Filament\Admin\Support\VoucherResource::urlFor($p) }}" class="text-primary-600 hover:underline">{{ $p->number }}</a></td>
                        <td>{{ __('cash.kinds.'.$kind) }}</td>
                        <td>{{ $p->contact?->name ?? $p->counterAccount?->name ?? '—' }}</td>
                        <td>{{ $p->paymentMethod?->name }}</td>
                        <td>{{ $p->creator?->name ?? '—' }}</td>
                        <td class="num" style="color: {{ $p->direction->value === 'in' ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }}">{{ $p->direction->value === 'in' ? '' : '-' }}{{ $fmt($p->amount) }}</td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif
    @if ($s->movements->isNotEmpty())
        <x-filament::section :heading="__('Cash in / out')">
            <table class="cs-table">
                @foreach ($s->movements as $m)
                    <tr><td>{{ $m->created_at->format('H:i') }}</td><td>{{ $m->direction->value === 'in' ? __('Cash in') : __('Cash out') }}</td>
                        <td>{{ $m->reason }}</td><td>{{ $m->creator?->name }}</td><td class="num">{{ $fmt($m->amount) }}</td></tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
