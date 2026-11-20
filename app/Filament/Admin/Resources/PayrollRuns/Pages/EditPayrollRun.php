<?php

namespace App\Filament\Admin\Resources\PayrollRuns\Pages;

use App\Domain\Payroll\Actions\UpdatePayrollLines;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Filament\Admin\Resources\PayrollRuns\PayrollLinesRepeater;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use App\Filament\Support\DomainGuard;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** The payroll sheet of a draft: one row per employee, every amount editable, net recalculated as you type. */
class EditPayrollRun extends EditRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = PayrollRunResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof PayrollRun ? __('Payroll :month', ['month' => $record->periodLabel()]).' — '.$record->number : parent::getTitle();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(2)->schema([
                DatePicker::make('posting_date')->label(__('Entry date'))->required(),
                Textarea::make('note')->label(__('Note'))->rows(1),
            ]),
            PayrollLinesRepeater::make(),
            PayrollLinesRepeater::totals(),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        assert($record instanceof PayrollRun);
        $advances = app(AdvanceBalances::class);

        $data['lines'] = $record->lines()->with('contact')->get()->sortBy('contact.name')
            ->mapWithKeys(fn (PayrollLine $line) => ['line-'.$line->id => PayrollLinesRepeater::row(
                $line, $line->contact->name, (string) $advances->openTotal($line->contact_id),
            )])->all();

        return [...$data, 'posting_date' => $record->posting_date->toDateString()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof PayrollRun);
        $lines = collect($data['lines'] ?? [])->mapWithKeys(fn (array $line) => [(int) $line['id'] => $line])->all();

        return $this->rescueDomain(fn () => app(UpdatePayrollLines::class)->handle($record, $lines,
            ['posting_date' => $data['posting_date'], 'note' => $data['note'] ?? null]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMissing')->label(__('Add missing employees'))->icon('heroicon-o-user-plus')->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Adds active employees with salary data that are not in this payroll or any other for the same month.'))
                ->action(function (PayrollRun $record) {
                    $added = DomainGuard::run(fn () => app(UpdatePayrollLines::class)->addMissing($record));
                    Notification::make()->success()->title(__(':count employees added', ['count' => $added]))->send();
                    $this->fillForm();
                }),
            ViewAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
