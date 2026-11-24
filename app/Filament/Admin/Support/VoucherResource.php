<?php

namespace App\Filament\Admin\Support;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Payment;
use App\Filament\Admin\Resources\PaymentVouchers\PaymentVoucherResource;
use App\Filament\Admin\Resources\ReceiptVouchers\ReceiptVoucherResource;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\UserLocations;
use App\Support\DocumentNumber;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Base of the two voucher pages: سندات القبض (money in, RV) and سندات الصرف (money out, PV).
 * Vouchers are created on these pages (any party: customer, supplier, employee or an account), on contact pages and on documents.
 */
abstract class VoucherResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Payment::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    abstract public static function direction(): PaymentDirection;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('direction', static::direction());
    }

    /** The page of a voucher, whichever way the money went. */
    public static function urlFor(Payment|int $payment, string $page = 'view'): string
    {
        $payment = $payment instanceof Payment ? $payment : Payment::query()->findOrFail($payment);
        $resource = $payment->direction === PaymentDirection::In ? ReceiptVoucherResource::class : PaymentVoucherResource::class;

        return $resource::getUrl($page, ['record' => $payment]);
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'payments.view' : false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('direction')->label(__('Type'))->badge(),
                TextEntry::make('contact.name')->label(__('Contact'))->placeholder('—'),
                TextEntry::make('counterAccount.name')->label(__('Counter account'))->visible(fn (Payment $r) => $r->counter_account_id !== null),
                TextEntry::make('amount')->label(__('Amount'))->numeric(2)->weight('bold')->size('lg'),
                TextEntry::make('paid_at')->label(__('Date'))->dateTime(),
                TextEntry::make('paymentMethod.name')->label(__('Payment method')),
                TextEntry::make('account.name')->label(__('Account')),
                TextEntry::make('reference')->label(__('Reference'))->placeholder('—'),
                TextEntry::make('cheque_number')->label(__('Cheque number'))->placeholder('—'),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn (Payment $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')->visible(fn (Payment $r) => $r->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Settled documents'))->columnSpanFull()->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()
                    ->table([TableColumn::make(__('Document')), TableColumn::make(__('Amount'))])
                    ->schema([
                        TextEntry::make('allocatable_id')->formatStateUsing(fn ($state, $record) => __('statement.kinds.'.$record->allocatable_type)
                            .' '.(DocumentNumber::of($record->allocatable) ?? '')),
                        TextEntry::make('amount')->numeric(2),
                    ])
                    ->placeholder(__('Not allocated — kept as an advance.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // like the old system, the list holds vouchers only: money taken on an invoice when it was made shows on that invoice
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact', 'counterAccount', 'paymentMethod', 'account', 'location', 'creator'])
                ->whereNotExists(fn ($sub) => static::takenWithInvoice($sub)))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (Payment $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('paid_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('contact.name')->label(static::direction() === PaymentDirection::In ? __('Received from') : __('Paid to'))->searchable()
                    ->placeholder(fn (Payment $r) => $r->counterAccount->name ?? '—')
                    ->description(fn (Payment $r) => $r->contact?->type->getLabel() ?? ($r->counterAccount ? __('Account') : null)),
                TextColumn::make('paymentMethod.name')->label(__('Payment method')),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2)->sortable()->weight('bold')
                    ->color(fn (Payment $r) => $r->voided_at ? 'gray' : static::direction()->getColor())
                    ->summarize(Sum::make()->label(__('Total'))->numeric(2)
                        ->query(fn (QueryBuilder $query) => $query->whereNull('voided_at'))),
                TextColumn::make('account.name')->label(__('Account'))->toggleable(),
                TextColumn::make('location.name')->label(__('Location'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference')->label(__('Reference'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('payment_method_id')->label(__('Payment method'))->relationship('paymentMethod', 'name'),
                SelectFilter::make('contact_id')->label(__('Contact'))->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Contact::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                    ->options(fn () => Contact::query()->limit(50)->pluck('name', 'id')),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options()),
                Filter::make('paid_at')->columns(2)->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('paid_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('paid_at', '<=', $date))),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')
                    ->url(fn (Payment $record) => route('vouchers.print', $record), shouldOpenInNewTab: true),
            ]);
    }

    /**
     * Payments taken together with an invoice / return (same moment as the document): the POS tenders, the cash paid
     * on an old-system invoice. Everything else is a voucher of its own.
     */
    protected static function takenWithInvoice(QueryBuilder $sub): void
    {
        $documents = ['sale' => ['sales', 'sale_date'], 'sale_return' => ['sale_returns', 'return_date'],
            'purchase' => ['purchases', 'purchase_date'], 'purchase_return' => ['purchase_returns', 'return_date']];

        $sub->from('payment_allocations as pa')->whereColumn('pa.payment_id', 'payments.id')
            ->where(function ($any) use ($documents) {
                foreach ($documents as $type => [$table, $date]) {
                    $any->orWhereExists(fn ($doc) => $doc->from($table)->where('pa.allocatable_type', $type)
                        ->whereColumn("{$table}.id", 'pa.allocatable_id')->whereColumn("{$table}.{$date}", 'payments.paid_at'));
                }
            });
    }
}
