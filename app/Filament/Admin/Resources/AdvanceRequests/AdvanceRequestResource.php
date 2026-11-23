<?php

namespace App\Filament\Admin\Resources\AdvanceRequests;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Payroll\Actions\AdvanceRequestWorkflow;
use App\Domain\Payroll\Enums\AdvanceRequestStatus;
use App\Domain\Payroll\Models\AdvanceRequest;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Filament\Admin\Resources\AdvanceRequests\Pages\ManageAdvanceRequests;
use App\Filament\Admin\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\NavigationGroup;
use App\Filament\Support\DomainGuard;
use App\Filament\Support\UserLocations;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * طلبات السلف: employees ask, managers approve or reject, then whoever pays advances gives them.
 * Someone who may only request sees their own requests; approvers and payers see their branches.
 */
class AdvanceRequestResource extends Resource
{
    use HasNavigationCount;

    protected static ?string $model = AdvanceRequest::class;

    protected static ?string $slug = 'advance-requests';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Payroll;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('Advance request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Advance requests');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->can('advances.request') || self::manages($user));
    }

    public static function canCreate(): bool
    {
        return false; // through the "request advance" action
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Approvers and payers see every request of their branches; the others only their own. */
    public static function manages(User $user): bool
    {
        return $user->can('advances.approve') || $user->can('advances.create');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }
        if (! self::manages($user)) {
            return $query->where(fn (Builder $q) => $q->where('requested_by', $user->id)
                ->orWhere('contact_id', $user->employeeProfile->contact_id ?? 0));
        }
        if ($user->isSuperAdmin() || $user->can('locations.access_all')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn('location_id', $user->locations()->pluck('locations.id'))->orWhereNull('location_id'));
    }

    /** Sidebar count: what waits for this user — requests to approve, or approved ones to pay. */
    protected static function navigationCount(Builder $query): Builder
    {
        $user = auth()->user();
        $statuses = [];
        if ($user instanceof User && $user->can('advances.approve')) {
            $statuses[] = AdvanceRequestStatus::Pending;
        }
        if ($user instanceof User && $user->can('advances.create')) {
            $statuses[] = AdvanceRequestStatus::Approved;
        }

        return $statuses === [] ? $query->whereRaw('1 = 0') : $query->whereIn('status', $statuses);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Advance requests waiting for you');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact', 'requester', 'decider', 'advance']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('contact.name')->label(__('Employee'))->searchable()->weight('bold')
                    ->description(fn (AdvanceRequest $r) => $r->created_at?->format('Y-m-d H:i')),
                TextColumn::make('amount')->label(__('Requested'))->numeric(2),
                TextColumn::make('approved_amount')->label(__('Approved'))->numeric(2)->placeholder('—')
                    ->color(fn (AdvanceRequest $r) => $r->approved_amount !== null && (float) $r->approved_amount !== (float) $r->amount ? 'warning' : null),
                TextColumn::make('installment')->label(__('Monthly installment'))->placeholder(__('All from the next payroll'))
                    ->state(fn (AdvanceRequest $r) => $r->payableInstallment() !== null ? number_format((float) $r->payableInstallment(), 2) : null),
                TextColumn::make('open_advances')->label(__('Open advances'))->numeric(2)
                    ->state(fn (AdvanceRequest $r) => (string) app(AdvanceBalances::class)->openTotal($r->contact_id))
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray')
                    ->visible(fn () => ($u = auth()->user()) instanceof User && self::manages($u)),
                TextColumn::make('reason')->label(__('Reason'))->placeholder('—')->wrap()->limit(60),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('decider.name')->label(__('Decided by'))->placeholder('—')
                    ->description(fn (AdvanceRequest $r) => $r->decision_note)->toggleable(),
                TextColumn::make('advance.number')->label(__('Advance'))->placeholder('—')
                    ->url(fn (AdvanceRequest $r) => $r->employee_advance_id ? EmployeeAdvanceResource::getUrl('view', ['record' => $r->employee_advance_id]) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(AdvanceRequestStatus::class)->multiple()
                    ->default(fn () => ($u = auth()->user()) instanceof User && self::manages($u)
                        ? [AdvanceRequestStatus::Pending->value, AdvanceRequestStatus::Approved->value] : null),
            ])
            ->recordActions([
                Action::make('approve')->label(__('Approve'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (AdvanceRequest $r) => $r->status === AdvanceRequestStatus::Pending && auth()->user()?->can('advances.approve')
                        && ! $r->isFor(self::user()))
                    ->fillForm(fn (AdvanceRequest $r) => ['amount' => (float) $r->amount, 'installment_amount' => $r->installment_amount !== null ? (float) $r->installment_amount : null])
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('amount')->label(__('Approved amount'))->numeric()->minValue(0.01)->required(),
                            TextInput::make('installment_amount')->label(__('Monthly installment'))->numeric()->minValue(0.01)
                                ->helperText(__('Deducted from each payroll. Leave empty to deduct it all from the next one.')),
                        ]),
                        TextInput::make('note')->label(__('Note'))->maxLength(255),
                    ])
                    ->action(function (AdvanceRequest $record, array $data) {
                        DomainGuard::run(fn () => app(AdvanceRequestWorkflow::class)->approve($record, self::user(), $data['amount'], $data['installment_amount'] ?? null, $data['note'] ?? null));
                        Notification::make()->success()->title(__('Request approved'))->send();
                    }),
                Action::make('reject')->label(__('Reject'))->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (AdvanceRequest $r) => $r->status === AdvanceRequestStatus::Pending && auth()->user()?->can('advances.approve')
                        && ! $r->isFor(self::user()))
                    ->schema([TextInput::make('note')->label(__('Reason'))->required()->maxLength(255)])
                    ->action(function (AdvanceRequest $record, array $data) {
                        DomainGuard::run(fn () => app(AdvanceRequestWorkflow::class)->reject($record, self::user(), $data['note']));
                        Notification::make()->success()->title(__('Request rejected'))->send();
                    }),
                Action::make('pay')->label(__('Pay'))->icon('heroicon-o-banknotes')->color('warning')
                    ->visible(fn (AdvanceRequest $r) => $r->status === AdvanceRequestStatus::Approved
                        && ($u = auth()->user()) instanceof User && $u->can('advances.create') && $u->can('payments.create'))
                    ->modalHeading(fn (AdvanceRequest $r) => __('Pay the advance of :name', ['name' => $r->contact->name]))
                    ->modalDescription(fn (AdvanceRequest $r) => __('Amount').': '.number_format((float) $r->payableAmount(), 2))
                    ->schema(fn (AdvanceRequest $record) => [
                        Grid::make(2)->schema([
                            Select::make('payment_method_id')->label(__('Payment method'))->required()
                                ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->orderBy('sort')->pluck('name', 'id'))
                                ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id')),
                            Select::make('location_id')->label(__('Location'))->required()->options(fn () => UserLocations::options())
                                ->default($record->location_id ?? UserLocations::first()),
                            DateTimePicker::make('date')->label(__('Date'))->default(now())->required(),
                        ]),
                    ])
                    ->action(function (AdvanceRequest $record, array $data) {
                        abort_unless(array_key_exists((int) $data['location_id'], UserLocations::options()), 403);
                        $advance = DomainGuard::run(fn () => app(AdvanceRequestWorkflow::class)
                            ->pay($record, (int) $data['payment_method_id'], (int) $data['location_id'], Carbon::parse($data['date'])));
                        Notification::make()->success()->title(__('Advance :number recorded', ['number' => $advance->number]))
                            ->actions([Action::make('print')->label(__('Print voucher'))->url(route('vouchers.print', $advance->payment_id), true)])
                            ->send();
                    }),
                Action::make('cancel')->label(__('Cancel request'))->icon('heroicon-o-minus-circle')->color('gray')->requiresConfirmation()
                    ->visible(fn (AdvanceRequest $r) => $r->status === AdvanceRequestStatus::Pending && $r->requested_by === auth()->id())
                    ->action(fn (AdvanceRequest $record) => DomainGuard::run(fn () => app(AdvanceRequestWorkflow::class)->cancel($record))),
            ]);
    }

    /** "Request advance": for yourself, or — for approvers and payers — for any employee. */
    public static function requestAction(): Action
    {
        return Action::make('requestAdvance')->label(__('Request an advance'))->icon('heroicon-o-hand-raised')->color('primary')
            ->visible(function (): bool {
                $user = auth()->user();

                return $user instanceof User && (self::manages($user) || ($user->can('advances.request') && $user->employeeProfile !== null));
            })
            ->schema(fn () => [
                Select::make('contact_id')->label(__('Employee'))->required()->searchable()
                    ->options(fn () => Contact::query()->where('type', ContactType::Employee)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => self::manages(self::user())),
                Grid::make(2)->schema([
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required(),
                    TextInput::make('installment_amount')->label(__('Monthly installment'))->numeric()->minValue(0.01)
                        ->helperText(__('Leave empty to deduct it all from the next payroll.')),
                ]),
                TextInput::make('reason')->label(__('Reason'))->maxLength(255),
            ])
            ->action(function (array $data) {
                $user = self::user();
                $employee = self::manages($user) && filled($data['contact_id'] ?? null)
                    ? Contact::query()->findOrFail($data['contact_id'])
                    : ($user->employeeProfile->contact ?? null);
                if ($employee === null) {
                    Notification::make()->danger()->title(__('payroll.errors.not_linked'))->send();

                    return;
                }
                DomainGuard::run(fn () => app(AdvanceRequestWorkflow::class)->request($user, $employee, $data['amount'], $data['installment_amount'] ?? null, $data['reason'] ?? null));
                Notification::make()->success()->title(__('Request sent for approval'))->send();
            });
    }

    private static function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdvanceRequests::route('/')];
    }
}
