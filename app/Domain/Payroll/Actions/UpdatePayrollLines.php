<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollTotals;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Edits the lines of a draft payroll (amounts, notes), removes lines and adds employees left out. */
final class UpdatePayrollLines
{
    public function __construct(
        private readonly PayrollTotals $totals,
        private readonly GeneratePayroll $generator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines  line id => editable fields (+ note); lines left out are removed
     * @param  array{posting_date?: string, note?: ?string}  $header
     */
    public function handle(PayrollRun $run, array $lines, array $header = []): PayrollRun
    {
        return DB::transaction(function () use ($run, $lines, $header) {
            $run = $this->lockDraft($run);
            $existing = $run->lines()->with('contact')->get()->keyBy('id');

            foreach ($existing as $id => $line) {
                if (! array_key_exists($id, $lines)) {
                    $line->delete();

                    continue;
                }
                $values = [];
                foreach (PayrollLine::EDITABLE as $column) {
                    $value = Decimal::of(filled($lines[$id][$column] ?? null) ? $lines[$id][$column] : 0);
                    $values[$column] = Decimal::toDb($value->isNegative() ? 0 : $value, $column === 'absence_days' ? 2 : 4);
                }
                $earned = PayrollCalculator::earned($values);
                $net = PayrollCalculator::net($values);
                if ($earned->isNegative()) {
                    throw new DomainException(__('payroll.errors.negative_earned', ['name' => $line->contact->name]));
                }
                if ($net->isNegative()) {
                    throw new DomainException(__('payroll.errors.negative_net', ['name' => $line->contact->name]));
                }
                $line->update([...$values, 'earned' => Decimal::toDb($earned), 'net' => Decimal::toDb($net),
                    'note' => $lines[$id]['note'] ?? null]);
            }

            if (filled($header['posting_date'] ?? null)) {
                $run->posting_date = Carbon::parse($header['posting_date']);
            }
            if (array_key_exists('note', $header)) {
                $run->note = $header['note'];
            }
            $run->save();

            return $this->totals->refresh($run);
        });
    }

    /**
     * Adds employees that became eligible after the draft was made (new hires, a profile filled in later).
     *
     * @param  ?list<int>  $contactIds
     */
    public function addMissing(PayrollRun $run, ?array $contactIds = null): int
    {
        return DB::transaction(function () use ($run, $contactIds) {
            $run = $this->lockDraft($run);
            $period = CarbonImmutable::parse($run->period);
            $employees = $this->generator->eligible($period, $run->location_id, $contactIds);
            foreach ($employees as $employee) {
                $run->lines()->create($this->generator->lineFor($employee, $period));
            }
            $this->totals->refresh($run);

            return $employees->count();
        });
    }

    private function lockDraft(PayrollRun $run): PayrollRun
    {
        $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
        if ($run->voided_at !== null || $run->status !== PayrollStatus::Draft) {
            throw new DomainException(__('payroll.errors.not_draft'));
        }

        return $run;
    }
}
