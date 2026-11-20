<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollTotals;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts a month's payroll as a draft: one line per active employee of the branch (or of all branches)
 * that has no payroll for that month yet, with their salary, fixed allowances and this month's advance
 * installments filled in. Everything stays editable until it is approved.
 */
final class GeneratePayroll
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly AdvanceBalances $advances,
        private readonly PayrollTotals $totals,
    ) {}

    /**
     * @param  ?list<int>  $contactIds  only these employees (default: everyone eligible)
     * @param  ?array<int, array<string, mixed>>  $lines  contact id => amounts as edited before saving; only these employees
     */
    public function handle(CarbonInterface $month, ?int $locationId = null, ?CarbonInterface $postingDate = null, ?string $note = null,
        ?array $contactIds = null, ?array $lines = null): PayrollRun
    {
        $period = CarbonImmutable::parse($month)->startOfMonth();
        if ($lines !== null) {
            $contactIds = array_map('intval', array_keys($lines));
        }

        return DB::transaction(function () use ($period, $locationId, $postingDate, $note, $contactIds, $lines) {
            $employees = $this->eligible($period, $locationId, $contactIds);
            if ($employees->isEmpty()) {
                throw new DomainException(__('payroll.errors.no_employees'));
            }

            $run = PayrollRun::query()->create([
                'number' => $this->numbers->next(DocumentType::Payroll, $locationId),
                'period' => $period->toDateString(),
                // the month's last day — or today while the month is still running, so salaries paid early aren't dated after their payout
                'posting_date' => ($postingDate ? CarbonImmutable::parse($postingDate) : $period->endOfMonth()->min(today()))->toDateString(),
                'location_id' => $locationId,
                'status' => PayrollStatus::Draft,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            foreach ($employees as $employee) {
                $run->lines()->create($this->lineFor($employee, $period));
            }
            if ($lines !== null) { // same checks as editing a draft
                $edited = $run->lines()->get()->mapWithKeys(fn (PayrollLine $line) => [$line->id => $lines[$line->contact_id]])->all();

                return app(UpdatePayrollLines::class)->handle($run, $edited);
            }

            return $this->totals->refresh($run);
        });
    }

    /** @return array<string, mixed> */
    public function lineFor(Contact $employee, CarbonImmutable $period): array
    {
        $profile = $employee->employeeProfile;
        $line = [
            'contact_id' => $employee->id,
            'base_salary' => Decimal::toDb($profile->base_salary ?? 0),
            'allowances' => Decimal::toDb($profile->fixed_allowances ?? 0),
        ];
        $earned = PayrollCalculator::earned($line);
        $line['advance_deduction'] = Decimal::toDb(BigDecimal::max(Decimal::of(0),
            BigDecimal::min($earned, $this->advances->suggestedDeduction($employee, $period->endOfMonth()))));
        $line['earned'] = Decimal::toDb($earned);
        $line['net'] = Decimal::toDb(PayrollCalculator::net($line));

        return $line;
    }

    /**
     * Active employees on the payroll that month without a (live) payroll for it yet.
     *
     * @param  ?list<int>  $contactIds
     * @return Collection<int, Contact>
     */
    public function eligible(CarbonImmutable $period, ?int $locationId, ?array $contactIds = null): Collection
    {
        $taken = PayrollLine::query()->whereHas('run', fn (Builder $query) => $query->whereNull('voided_at')->whereDate('period', $period->toDateString()))
            ->pluck('contact_id');

        return Contact::query()
            ->where('type', ContactType::Employee)->where('is_active', true)
            ->whereNotIn('id', $taken)
            ->when($contactIds !== null, fn (Builder $query) => $query->whereIn('id', $contactIds))
            ->whereHas('employeeProfile', fn (Builder $query) => $query
                ->where('status', EmployeeStatus::Active)
                ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $period->endOfMonth()->toDateString()))
                ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $period->toDateString()))
                ->when($locationId, fn (Builder $q, int $id) => $q->where('location_id', $id)))
            ->with('employeeProfile')->orderBy('name')
            ->get();
    }
}
