<?php

namespace App\Filament\Admin\Resources\PayrollRuns;

use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\PayrollRuns\Pages\CreatePayrollRun;
use App\Filament\Admin\Resources\PayrollRuns\Pages\EditPayrollRun;
use App\Filament\Admin\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Admin\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\Model;

class PayrollRunResource extends Resource
{
    use AuthorizesWithPermissions;
    use HasNavigationCount;
    use ScopesToUserLocations;

    protected static ?string $model = PayrollRun::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Payroll;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Payroll run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payroll runs');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'payroll.view',
            'create', 'update' => 'payroll.create',
            default => false, // voided, never deleted
        };
    }

    /** Only drafts are edited; an approved payroll is reopened first. */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && $record instanceof PayrollRun && $record->voided_at === null && $record->status === PayrollStatus::Draft;
    }

    /** Users tied to some branches must pick one; "all branches" needs access to all of them. */
    public static function canUseAllLocations(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->can('locations.access_all'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(2)->schema([
                DatePicker::make('period')->label(__('Month'))->default(now()->startOfMonth())->required()
                    ->helperText(__('Any day of the month will do.')),
                Select::make('location_id')->label(__('Location'))->options(fn () => self::userLocationOptions())
                    ->placeholder(__('All branches'))->required(fn () => ! self::canUseAllLocations())
                    ->helperText(__('A branch payroll includes only its own employees.')),
                DatePicker::make('posting_date')->label(__('Entry date'))
                    ->helperText(__('Date of the salaries entry. Empty = the last day of the month (or today, for the current month).')),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('period')->label(__('Month'))->state(fn (PayrollRun $r) => $r->periodLabel()),
                TextEntry::make('location.name')->label(__('Location'))->placeholder(__('All branches')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('total_earned')->label(__('Total salaries'))->numeric(2),
                TextEntry::make('total_advances')->label(__('Advance deductions'))->numeric(2),
                TextEntry::make('total_net')->label(__('Net to pay'))->numeric(2)->weight('bold')->size('lg'),
                TextEntry::make('total_paid')->label(__('Paid'))->numeric(2)
                    ->color(fn (PayrollRun $r) => (float) $r->total_paid < (float) $r->total_net ? 'warning' : 'success'),
                TextEntry::make('posting_date')->label(__('Entry date'))->date(),
                TextEntry::make('approved_at')->label(__('Approved'))->dateTime()->placeholder('—')
                    ->helperText(fn (PayrollRun $r) => $r->approver?->name),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn (PayrollRun $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')->visible(fn (PayrollRun $r) => $r->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Employees'))->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->hiddenLabel()
                    ->state(fn (PayrollRun $record) => $record->lines->sortBy('contact.name')->values())
                    ->table([
                        TableColumn::make(__('Employee')), TableColumn::make(__('Salary')), TableColumn::make(__('Additions')),
                        TableColumn::make(__('Deductions')), TableColumn::make(__('Earned')), TableColumn::make(__('Advance')),
                        TableColumn::make(__('Net')), TableColumn::make(__('Payment voucher')),
                    ])
                    ->schema([
                        TextEntry::make('contact.name')->url(fn (PayrollLine $record) => EmployeeResource::getUrl('view', ['record' => $record->contact_id]))
                            ->helperText(fn (PayrollLine $record) => $record->note),
                        TextEntry::make('base_salary')->numeric(2),
                        TextEntry::make('additions')->state(fn (PayrollLine $r) => (float) $r->allowances + (float) $r->overtime + (float) $r->bonus)->numeric(2),
                        TextEntry::make('deductions')->numeric(2)
                            ->state(fn (PayrollLine $r) => (float) $r->absence_deduction + (float) $r->penalties + (float) $r->other_deductions)
                            ->helperText(fn (PayrollLine $r) => (float) $r->absence_days > 0 ? __(':days days absent', ['days' => (float) $r->absence_days]) : null),
                        TextEntry::make('earned')->numeric(2),
                        TextEntry::make('advance_deduction')->numeric(2),
                        TextEntry::make('net')->numeric(2)->weight('bold'),
                        TextEntry::make('payment.number')->placeholder(fn (PayrollLine $r) => $r->isPaid() ? '—' : __('Not paid'))
                            ->url(fn (PayrollLine $r) => $r->payment_id ? route('vouchers.print', $r->payment_id) : null, true),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('location')->withCount('lines'))
            ->defaultSort('period', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (PayrollRun $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('period')->label(__('Month'))->formatStateUsing(fn (PayrollRun $record) => $record->periodLabel())->sortable(),
                TextColumn::make('location.name')->label(__('Location'))->placeholder(__('All branches')),
                TextColumn::make('lines_count')->label(__('Employees'))->numeric(),
                TextColumn::make('total_earned')->label(__('Total salaries'))->numeric(2)->summarize(Sum::make()->numeric(2)),
                TextColumn::make('total_net')->label(__('Net to pay'))->numeric(2)->summarize(Sum::make()->numeric(2)),
                TextColumn::make('total_paid')->label(__('Paid'))->numeric(2)->summarize(Sum::make()->numeric(2)),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(PayrollStatus::class),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => self::userLocationOptions()),
                Filter::make('year')->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('to')->label(__('To')),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('period', '>=', $d))
                    ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('period', '<=', $d))),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->where('status', '!=', PayrollStatus::Paid);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Payrolls not fully paid');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
            'create' => CreatePayrollRun::route('/create'),
            'view' => ViewPayrollRun::route('/{record}'),
            'edit' => EditPayrollRun::route('/{record}/edit'),
        ];
    }
}
