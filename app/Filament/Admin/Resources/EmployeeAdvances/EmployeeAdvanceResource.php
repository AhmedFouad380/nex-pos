<?php

namespace App\Filament\Admin\Resources\EmployeeAdvances;

use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Filament\Admin\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Filament\Admin\Resources\EmployeeAdvances\Pages\ViewEmployeeAdvance;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeAdvanceResource extends Resource
{
    use AuthorizesWithPermissions;
    use HasNavigationCount;
    use ScopesToUserLocations;

    protected static ?string $model = EmployeeAdvance::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Payroll;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Advance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Advances');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'advances.view',
            default => false, // given from the "give advance" action, voided — never edited
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('contact.name')->label(__('Employee'))
                    ->url(fn (EmployeeAdvance $r) => EmployeeResource::getUrl('view', ['record' => $r->contact_id])),
                TextEntry::make('advance_date')->label(__('Date'))->dateTime(),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('amount')->label(__('Amount'))->numeric(2)->weight('bold')->size('lg'),
                TextEntry::make('installment_amount')->label(__('Monthly installment'))->numeric(2)->placeholder(__('All in the next payroll')),
                TextEntry::make('settled_total')->label(__('Repaid'))->numeric(2),
                TextEntry::make('remaining')->label(__('Remaining'))->numeric(2)->color(fn ($state) => (float) $state > 0 ? 'warning' : 'success'),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('payment.number')->label(__('Payment voucher'))
                    ->url(fn (EmployeeAdvance $r) => $r->payment_id ? route('vouchers.print', $r->payment_id) : null, true),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn (EmployeeAdvance $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')->visible(fn (EmployeeAdvance $r) => $r->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Repayments'))->columnSpanFull()->schema([
                RepeatableEntry::make('settlements')->hiddenLabel()
                    ->table([TableColumn::make(__('Date')), TableColumn::make(__('Repaid by')), TableColumn::make(__('Amount'))])
                    ->schema([
                        TextEntry::make('settled_at')->date(),
                        TextEntry::make('source')
                            ->state(fn ($record) => $record->payroll_line_id
                                ? __('Payroll run').' '.$record->payrollLine->run->number
                                : __('Cash repayment').' '.$record->payment?->number)
                            ->url(fn ($record) => $record->payroll_line_id
                                ? PayrollRunResource::getUrl('view', ['record' => $record->payrollLine->payroll_run_id])
                                : ($record->payment_id ? route('vouchers.print', $record->payment_id) : null)),
                        TextEntry::make('amount')->numeric(2),
                    ])
                    ->placeholder(__('Nothing repaid yet.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact', 'location']))
            ->defaultSort('advance_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (EmployeeAdvance $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('contact.name')->label(__('Employee'))->searchable(),
                TextColumn::make('advance_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2)->sortable(),
                TextColumn::make('installment_amount')->label(__('Monthly installment'))->numeric(2)->placeholder('—'),
                TextColumn::make('remaining')->label(__('Remaining'))->numeric(2)->sortable()->summarize(Sum::make()->numeric(2)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('location.name')->label(__('Location'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('contact_id')->label(__('Employee'))->relationship('contact', 'name')->searchable(),
                SelectFilter::make('status')->label(__('Status'))->options(AdvanceStatus::class),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->where('status', AdvanceStatus::Open);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Open advances');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeAdvances::route('/'),
            'view' => ViewEmployeeAdvance::route('/{record}'),
        ];
    }
}
