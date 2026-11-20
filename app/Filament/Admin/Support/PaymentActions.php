<?php

namespace App\Filament\Admin\Support;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\UserLocations;
use App\Support\Decimal;
use App\Support\DomainException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** "Record payment" modal used on contacts (auto-allocate) and on documents (allocate to that document). */
final class PaymentActions
{
    /** Shared payment fields (method, amount, date, references). */
    public static function fields(?Closure $defaultAmount = null): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required()
                    ->default($defaultAmount),
                Select::make('payment_method_id')->label(__('Payment method'))->required()->live()
                    ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->orderBy('sort')->pluck('name', 'id'))
                    ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id')),
                DateTimePicker::make('paid_at')->label(__('Date'))->default(now())->required(),
                TextInput::make('reference')->label(__('Reference'))->maxLength(100),
                TextInput::make('cheque_number')->label(__('Cheque number'))->maxLength(60)
                    ->visible(fn (Get $get) => self::isCheque($get('payment_method_id'))),
                DatePicker::make('cheque_date')->label(__('Cheque date'))
                    ->visible(fn (Get $get) => self::isCheque($get('payment_method_id'))),
                TextInput::make('bank_name')->label(__('Bank'))->maxLength(255)
                    ->visible(fn (Get $get) => self::isCheque($get('payment_method_id'))),
            ]),
            Textarea::make('note')->label(__('Note'))->rows(2),
        ];
    }

    /** On a contact page: money in from / out to the contact, spread over open documents oldest-first. */
    public static function forContact(string $name, PaymentDirection $direction, string $label, string $permission): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($direction === PaymentDirection::In ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-arrow-up-tray')
            ->color($direction === PaymentDirection::In ? 'success' : 'warning')
            ->visible(fn () => auth()->user()?->can($permission))
            ->schema([
                ...self::fields(),
                Toggle::make('auto_allocate')->label(__('Settle the oldest open invoices automatically'))->default(true)
                    ->helperText(__('Any remainder stays as an advance on the account.')),
            ])
            ->action(fn (Contact $record, array $data) => self::record($direction, $record->id, null, $data, [], (bool) $data['auto_allocate']));
    }

    /** On a document page: pay (part of) what is due on this document. */
    public static function forDocument(PaymentDirection $direction, Closure $contactId, Closure $locationId, string $permission): Action
    {
        return Action::make('addPayment')
            ->label(__('Add payment'))->icon('heroicon-o-banknotes')->color('success')
            ->visible(fn (Model $record) => $record->getAttribute('voided_at') === null
                && Decimal::of($record->getAttribute('due_total'))->isPositive() && auth()->user()?->can($permission))
            ->schema(fn (Model $record) => self::fields(fn () => (float) $record->getAttribute('due_total')))
            ->action(fn (Model $record, array $data) => self::record($direction, $contactId($record), $locationId($record), $data,
                [[$record, (string) min((float) $data['amount'], (float) $record->getAttribute('due_total'))]]));
    }

    public static function record(PaymentDirection $direction, ?int $contactId, ?int $locationId, array $data, array $allocations = [], bool $auto = false,
        ?int $counterAccountId = null): void
    {
        try {
            $payment = app(RecordPayment::class)->handle(new PaymentData(
                direction: $direction,
                paymentMethodId: (int) $data['payment_method_id'],
                amount: (string) $data['amount'],
                contactId: $contactId,
                locationId: $locationId,
                paidAt: Carbon::parse($data['paid_at'] ?? now()),
                allocations: $allocations,
                autoAllocate: $auto,
                counterAccountId: $counterAccountId,
                reference: $data['reference'] ?? null,
                chequeNumber: $data['cheque_number'] ?? null,
                chequeDate: $data['cheque_date'] ?? null,
                bankName: $data['bank_name'] ?? null,
                note: $data['note'] ?? null,
            ));
            Notification::make()->success()->title(__('Payment :number recorded', ['number' => $payment->number]))
                ->actions([Action::make('print')->label(__('Print voucher'))->url(route('vouchers.print', $payment), shouldOpenInNewTab: true)])
                ->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    /**
     * "New voucher" on the receipt / payment voucher pages: money from / to a customer or a supplier,
     * settling their open documents oldest-first; a payment voucher may also go to an account (a bill, a loan,
     * the owner's drawings…). Salaries and advances have their own screens.
     */
    public static function voucher(PaymentDirection $direction): Action
    {
        $in = $direction === PaymentDirection::In;
        $types = fn (?string $party): array => match ($party) {
            'customer' => [ContactType::Customer, ContactType::Both],
            'supplier' => [ContactType::Supplier, ContactType::Both],
            default => [],
        };
        $contacts = fn (?string $party, ?string $search = null) => Contact::query()->whereIn('type', $types($party))
            ->when($search, fn ($query, $search) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')->limit(50)->pluck('name', 'id')->all();

        return Action::make('newVoucher')
            ->label($in ? __('New receipt voucher') : __('New payment voucher'))
            ->icon($in ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-arrow-up-tray')
            ->color($in ? 'success' : 'warning')
            ->modalHeading($in ? __('New receipt voucher') : __('New payment voucher'))
            ->visible(fn () => auth()->user()?->can($in ? 'vouchers.receipt' : 'vouchers.payment'))
            ->schema([
                Grid::make(2)->schema([
                    ToggleButtons::make('party')->label($in ? __('Received from') : __('Paid to'))->inline()->required()->live()
                        ->options(['customer' => __('Customer'), 'supplier' => __('Supplier'), ...($in ? [] : ['account' => __('Account')])])
                        ->default($in ? 'customer' : 'supplier')
                        ->afterStateUpdated(fn (Set $set) => $set('contact_id', null))->columnSpanFull(),
                    Select::make('contact_id')->label(__('Name'))->searchable()->required()
                        ->visible(fn (Get $get) => $get('party') !== 'account')
                        ->options(fn (Get $get) => $contacts($get('party')))
                        ->getSearchResultsUsing(fn (Get $get, string $search) => $contacts($get('party'), $search))
                        ->live()
                        ->helperText(function (Get $get) {
                            $balance = filled($get('contact_id')) ? Contact::query()->whereKey($get('contact_id'))->value('balance') : null;
                            if ($balance === null) {
                                return null;
                            }
                            $amount = number_format(abs((float) $balance), 2);

                            return match (true) {
                                (float) $balance > 0 => __('Owes us :amount', ['amount' => $amount]),
                                (float) $balance < 0 => __('We owe them :amount', ['amount' => $amount]),
                                default => __('Settled account'),
                            };
                        }),
                    Select::make('counter_account_id')->label(__('Paid for (account)'))
                        ->options(fn () => AccountOptions::manual())->searchable()->required()
                        ->visible(fn (Get $get) => $get('party') === 'account'),
                    Select::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options())
                        ->default(fn () => UserLocations::first()),
                ]),
                ...self::fields(),
                Toggle::make('auto_allocate')->label(__('Settle the oldest open invoices automatically'))->default(true)
                    ->helperText(__('Any remainder stays as an advance on the account.'))
                    ->visible(fn (Get $get) => $get('party') !== 'account'),
            ])
            ->action(function (array $data) use ($direction, $types, $in) {
                $location = filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null;
                abort_unless($location === null || array_key_exists($location, UserLocations::options()), 403);

                if (! $in && ($data['party'] ?? null) === 'account') {
                    self::record($direction, null, $location, $data, counterAccountId: (int) $data['counter_account_id']);

                    return;
                }

                $contact = Contact::query()->whereKey($data['contact_id'] ?? 0)->whereIn('type', $types($data['party'] ?? null))->firstOrFail();
                self::record($direction, $contact->id, $location, $data, [], (bool) ($data['auto_allocate'] ?? false));
            });
    }

    private static function isCheque(mixed $methodId): bool
    {
        return $methodId && PaymentMethod::query()->whereKey($methodId)->value('type') === PaymentMethodType::Cheque->value;
    }
}
