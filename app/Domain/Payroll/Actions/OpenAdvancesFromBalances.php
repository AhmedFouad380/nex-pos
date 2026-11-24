<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The old system had no advances: money handed to an employee was a payment voucher on their account and salaries
 * were journal entries, so what an employee still owes is their account balance. This turns that balance into
 * advances, one per payment that is still owed: salaries cover the oldest payments first, so the balance is spread
 * over the newest payments, the oldest of them in part. The payments already exist; nothing moves again.
 * Each employee gets a payroll profile if missing. Employees that already have advances are left alone.
 */
final class OpenAdvancesFromBalances
{
    /** No payment or salary for this long: the profile is created suspended, not active. */
    private const IDLE_DAYS = 180;

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  list<string>  $exceptCodes  contact codes to skip (accounts that are not people)
     * @return list<array{employee: string, code: ?string, balance: string, advances: int, profile: ?string}>
     */
    public function handle(array $exceptCodes = [], bool $dryRun = false): array
    {
        $employees = Contact::query()->where('type', ContactType::Employee)->where('balance', '>', 0)
            ->when($exceptCodes, fn ($query) => $query->whereNotIn('code', $exceptCodes))
            ->whereDoesntHave('advances', fn ($query) => $query->whereNull('voided_at'))
            ->orderByDesc('balance')->get();

        $report = [];
        foreach ($employees as $employee) {
            $plan = $this->plan($employee);
            if ($plan === []) {
                continue;
            }
            $profile = EmployeeProfile::query()->where('contact_id', $employee->id)->exists() ? null : $this->profileFor($employee);
            if (! $dryRun) {
                DB::transaction(fn () => $this->apply($employee, $plan, $profile));
            }
            $report[] = ['employee' => $employee->name, 'code' => $employee->code, 'balance' => Decimal::toDb($employee->balance, 2),
                'advances' => count($plan), 'profile' => $profile['status'] ?? null];
        }

        return $report;
    }

    /**
     * The payments still owed, newest first: [payment, open amount].
     *
     * @return list<array{0: Payment, 1: BigDecimal}>
     */
    private function plan(Contact $employee): array
    {
        $owed = Decimal::of($employee->balance);
        $plan = [];
        $payments = Payment::query()->where('contact_id', $employee->id)->where('direction', PaymentDirection::Out)
            ->whereNull('voided_at')->whereNotIn('id', EmployeeAdvance::query()->whereNotNull('payment_id')->select('payment_id'))
            ->orderByDesc('paid_at')->orderByDesc('id')->cursor();
        foreach ($payments as $payment) {
            if (! $owed->isPositive()) {
                break;
            }
            $open = BigDecimal::min(Decimal::of($payment->amount), $owed);
            $plan[] = [$payment, $open];
            $owed = $owed->minus($open);
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    private function profileFor(Contact $employee): array
    {
        $payments = Payment::query()->where('contact_id', $employee->id)->whereNull('voided_at');
        $salaries = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.is_manual', true)->where('jl.contact_id', $employee->id)->where('jl.credit', '>', 0);
        $last = collect([(clone $payments)->max('paid_at'), (clone $salaries)->max('jl.entry_date')])->filter()->max();
        $lastSalary = (clone $salaries)->orderByDesc('jl.entry_date')->first(['jl.credit', 'jl.entry_date']);
        $active = $last !== null && now()->diffInDays($last, absolute: true) <= self::IDLE_DAYS;

        return [
            'contact_id' => $employee->id,
            // the branch that paid them most in the last months, else ever
            'location_id' => (clone $payments)->where('paid_at', '>=', now()->subDays(self::IDLE_DAYS))->groupBy('location_id')
                ->orderByRaw('count(*) desc')->value('location_id')
                ?? (clone $payments)->groupBy('location_id')->orderByRaw('count(*) desc')->value('location_id'),
            'hire_date' => collect([(clone $payments)->min('paid_at'), (clone $salaries)->min('jl.entry_date')])->filter()->min(),
            'status' => $active ? EmployeeStatus::Active->value : EmployeeStatus::Suspended->value,
            // the last monthly salary the old system posted, when it is recent
            'base_salary' => $lastSalary && now()->diffInDays($lastSalary->entry_date, absolute: true) <= 70 ? $lastSalary->credit : 0,
            'notes' => __('payroll.legacy_profile_note'),
        ];
    }

    /**
     * @param  list<array{0: Payment, 1: BigDecimal}>  $plan
     * @param  array<string, mixed>|null  $profile
     */
    private function apply(Contact $employee, array $plan, ?array $profile): void
    {
        if ($profile !== null) {
            EmployeeProfile::query()->create($profile);
        }
        foreach ($plan as [$payment, $open]) {
            $whole = Decimal::of($payment->amount)->isEqualTo($open);
            EmployeeAdvance::query()->create([
                'number' => $this->numbers->next(DocumentType::EmployeeAdvance, $payment->location_id),
                'contact_id' => $employee->id,
                'location_id' => $payment->location_id,
                'payment_id' => $payment->id,
                'advance_date' => $payment->paid_at,
                'amount' => Decimal::toDb($open),
                'remaining' => Decimal::toDb($open),
                'status' => AdvanceStatus::Open,
                'note' => $whole
                    ? __('payroll.legacy_advance_note', ['number' => $payment->number])
                    : __('payroll.legacy_advance_part_note', ['number' => $payment->number, 'amount' => number_format((float) $payment->amount, 2)]),
                'created_by' => auth()->id(),
            ]);
        }
    }
}
