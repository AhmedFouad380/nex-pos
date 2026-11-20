<?php

namespace App\Filament\Admin\Resources\PayrollRuns\Pages;

use App\Domain\Payroll\Actions\GeneratePayroll;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Filament\Admin\Resources\PayrollRuns\PayrollLinesRepeater;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * New payroll: pick the month and branch, the branch's employees appear with their salary, open advances
 * and this month's installment; adjust any amount (or drop an employee) and save.
 */
class CreatePayrollRun extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = PayrollRunResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        $reload = fn (Get $get, Set $set) => $set('lines', self::preview($get('period'), $get('location_id')));

        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                DatePicker::make('period')->label(__('Month'))->default(now()->startOfMonth()->toDateString())->required()
                    ->helperText(__('Any day of the month will do.'))->live()->afterStateUpdated($reload),
                Select::make('location_id')->label(__('Location'))->options(fn () => PayrollRunResource::userLocationOptions())
                    ->placeholder(__('All branches'))->required(fn () => ! PayrollRunResource::canUseAllLocations())
                    ->default(fn () => PayrollRunResource::canUseAllLocations() ? null : array_key_first(PayrollRunResource::userLocationOptions()))
                    ->live()->afterStateUpdated($reload),
                DatePicker::make('posting_date')->label(__('Entry date'))
                    ->helperText(__('Date of the salaries entry. Empty = the last day of the month (or today, for the current month).')),
                Textarea::make('note')->label(__('Note'))->rows(1),
            ]),
            PayrollLinesRepeater::make()
                ->helperText(__('Employees of the branch with salary data and no payroll for this month yet. Remove a row to leave someone out.')),
            PayrollLinesRepeater::totals(),
        ]);
    }

    protected function afterFill(): void
    {
        $this->data['lines'] = self::preview($this->data['period'] ?? null, $this->data['location_id'] ?? null);
    }

    /**
     * Rows for every employee the payroll would include, keyed for the repeater.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function preview(mixed $period, mixed $locationId): array
    {
        if (blank($period)) {
            return [];
        }
        $month = CarbonImmutable::parse($period)->startOfMonth();
        $generator = app(GeneratePayroll::class);
        $advances = app(AdvanceBalances::class);

        return $generator->eligible($month, filled($locationId) ? (int) $locationId : null)
            ->mapWithKeys(fn ($employee) => ['employee-'.$employee->id => PayrollLinesRepeater::row(
                $generator->lineFor($employee, $month), $employee->name,
                (string) $advances->openTotal($employee, $month->endOfMonth()),
            )])->all();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $locationId = filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null;
        abort_unless($locationId === null ? PayrollRunResource::canUseAllLocations()
            : array_key_exists($locationId, PayrollRunResource::userLocationOptions()), 403);

        $lines = collect($data['lines'] ?? [])->mapWithKeys(fn (array $line) => [(int) $line['contact_id'] => $line])->all();
        if ($lines === []) {
            Notification::make()->danger()->title(__('payroll.errors.no_employees'))->send();

            throw new Halt;
        }

        return $this->rescueDomain(fn () => app(GeneratePayroll::class)->handle(
            Carbon::parse($data['period']),
            $locationId,
            filled($data['posting_date'] ?? null) ? Carbon::parse($data['posting_date']) : null,
            $data['note'] ?? null,
            lines: $lines,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
