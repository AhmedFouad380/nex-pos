<?php

namespace App\Filament\Admin\Resources\Wallets\Transactions;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Wallet\Actions\ConfirmWalletTransaction;
use App\Domain\Wallet\Actions\RejectWalletTransaction;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletServiceRate;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\WalletSuggestions;
use App\Filament\Support\DomainGuard;
use App\Models\User;
use App\Support\Decimal;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

/** "Confirm" / "reject" for a pending wallet transaction — the only way one becomes a voucher. */
final class WalletTransactionActions
{
    public static function confirm(?Closure $after = null): Action
    {
        return Action::make('confirm')->label(__('Confirm'))->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (WalletTransaction $record) => $record->status === Status::Pending && auth()->user()?->can('wallet.confirm'))
            ->modalHeading(fn (WalletTransaction $record) => __('Confirm :amount', ['amount' => number_format((float) $record->amount, 2)])
                .' — '.($record->counterparty_name ?? $record->counterparty_phone ?? ''))
            ->modalDescription(__('A voucher is recorded only after you confirm what this transfer was for.'))
            ->fillForm(function (WalletTransaction $record) {
                $first = app(WalletSuggestions::class)->for($record)[0] ?? null;

                return [
                    'wallet_id' => $record->wallet_id,
                    // nothing known about the sender: in a branch that sells the wallet service, it is most likely that
                    'target_type' => $first['target']->value ?? (WalletServiceRate::query()->where('location_id', $record->location_id)->exists()
                        ? WalletTarget::Service->value : WalletTarget::Contact->value),
                    'target_id' => $first['id'] ?? null,
                    'commission' => self::defaultCommission($record),
                ];
            })
            ->schema(fn (WalletTransaction $record) => [
                Select::make('wallet_id')->label(__('Wallet number'))->required()
                    ->options(fn () => Wallet::query()->where('is_active', true)->where('provider', $record->provider)->pluck('name', 'id'))
                    ->visible($record->wallet_id === null),
                Select::make('target_type')->label(__('This transfer is for'))->required()->live()
                    ->options(collect(WalletTarget::cases())->filter(fn (WalletTarget $t) => $record->direction && $t->allows($record->direction))
                        ->mapWithKeys(fn (WalletTarget $t) => [$t->value => $t->getLabel()]))
                    ->afterStateUpdated(fn (Set $set) => $set('target_id', null)),
                TextInput::make('commission')->label(__('Commission'))->numeric()->minValue(0)
                    ->visible(fn (Get $get) => $get('target_type') === WalletTarget::Service->value)
                    ->default(fn () => self::defaultCommission($record))
                    ->disabled(fn () => ! auth()->user()?->can('wallet.edit_commission'))->live(onBlur: true)
                    ->helperText(fn (Get $get) => self::serviceHint($record, $get('commission'))),
                Select::make('target_id')->label(__('Choose'))->required()->searchable()
                    ->hidden(fn (Get $get) => $get('target_type') === WalletTarget::Service->value)
                    ->options(fn (Get $get) => self::targetOptions((string) $get('target_type'), '', $record))
                    ->getSearchResultsUsing(fn (Get $get, string $search) => self::targetOptions((string) $get('target_type'), $search, $record))
                    ->getOptionLabelUsing(fn (Get $get, $value) => self::targetOptions((string) $get('target_type'), '', $record, (int) $value)[(int) $value] ?? null)
                    ->helperText(function () use ($record) {
                        $hints = collect(app(WalletSuggestions::class)->for($record))->map(fn ($s) => $s['label'].' ('.$s['reason'].')');

                        return $hints->isNotEmpty() ? __('Suggested: :list', ['list' => $hints->implode('، ')]) : null;
                    }),
                TextInput::make('note')->label(__('Note'))->maxLength(255),
            ])
            ->action(function (WalletTransaction $record, array $data) use ($after) {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $target = WalletTarget::from($data['target_type']);
                $done = DomainGuard::run(fn () => app(ConfirmWalletTransaction::class)->handle($record, $target,
                    (int) ($data['target_id'] ?? 0), $user, isset($data['wallet_id']) ? (int) $data['wallet_id'] : null, $data['note'] ?? null,
                    $target === WalletTarget::Service && $user->can('wallet.edit_commission') ? ($data['commission'] ?? null) : null));
                Notification::make()->success()->title(__('Recorded as :number', ['number' => $done->payment?->number]))
                    ->actions([Action::make('print')->label(__('Print voucher'))->url(route('vouchers.print', $done->payment_id), true)])
                    ->send();
                $after && $after();
            });
    }

    public static function reject(?Closure $after = null): Action
    {
        return Action::make('reject')->label(__('Reject'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (WalletTransaction $record) => in_array($record->status, [Status::Pending, Status::Unparsed, Status::Duplicate], true)
                && auth()->user()?->can('wallet.confirm'))
            ->requiresConfirmation()
            ->modalDescription(__('Nothing is recorded; the message stays in the log.'))
            ->schema([TextInput::make('reason')->label(__('Reason'))->required()->maxLength(255)
                ->datalist([__('Personal transfer'), __('Test'), __('Already recorded by hand')])])
            ->action(function (WalletTransaction $record, array $data) use ($after) {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                DomainGuard::run(fn () => app(RejectWalletTransaction::class)->handle($record, $user, $data['reason']));
                Notification::make()->success()->title(__('Rejected'))->send();
                $after && $after();
            });
    }

    /** The branch rate on this amount (percentage with its minimum). */
    public static function defaultCommission(WalletTransaction $transaction): ?string
    {
        if ($transaction->direction === null || $transaction->amount === null) {
            return null;
        }

        return Decimal::toDb(WalletServiceRate::commissionFor($transaction->location_id, $transaction->direction, (string) $transaction->amount), 2);
    }

    /** What the cashier takes from / hands to the customer. */
    public static function serviceHint(WalletTransaction $transaction, mixed $commission): ?string
    {
        if ($transaction->amount === null) {
            return null;
        }
        $amount = (float) $transaction->amount;
        $commission = is_numeric($commission) ? (float) $commission : (float) self::defaultCommission($transaction);

        return $transaction->direction === PaymentDirection::Out
            ? __('Take from the customer :total in cash (:amount + commission :commission).', ['total' => number_format($amount + $commission, 2),
                'amount' => number_format($amount, 2), 'commission' => number_format($commission, 2)])
            : __('Give the customer :total in cash (:amount − commission :commission).', ['total' => number_format($amount - $commission, 2),
                'amount' => number_format($amount, 2), 'commission' => number_format($commission, 2)]);
    }

    /** @return array<int, string> */
    public static function targetOptions(string $type, string $search, WalletTransaction $transaction, ?int $id = null): array
    {
        return match (WalletTarget::tryFrom($type)) {
            WalletTarget::Contact => self::search(Contact::query()->where('is_active', true), ['name', 'mobile', 'code'], $search, $id)
                ->orderBy('name')->limit(50)->get()
                ->mapWithKeys(fn (Contact $c) => [$c->id => $c->name.' — '.$c->type->getLabel().($c->mobile ? ' · '.$c->mobile : '')])->all(),
            WalletTarget::Sale => self::search(Sale::query()->where('status', SaleStatus::Final)->whereNull('voided_at')
                ->where('location_id', $transaction->location_id)->when($id === null, fn (Builder $q) => $q->where('due_total', '>', 0)), ['invoice_no'], $search, $id)
                ->with('customer')->latest('sale_date')->limit(50)->get()
                ->mapWithKeys(fn (Sale $s) => [$s->id => $s->invoice_no.' — '.$s->customer?->name.' ('.number_format((float) $s->due_total, 2).')'])->all(),
            WalletTarget::Expense => self::search(ExpenseCategory::query(), ['name'], $search, $id)->orderBy('name')->pluck('name', 'id')->all(),
            WalletTarget::Service => [],
            WalletTarget::Account => self::search(Account::query()->where('is_postable', true)->where('is_active', true)
                ->where(fn (Builder $b) => $b->whereNull('sub_type')->orWhereNotIn('sub_type', RecordJournalEntry::CONTROL_ACCOUNTS)), ['name', 'code'], $search, $id)
                ->orderBy('code')->limit(80)->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->code.' — '.$a->name])->all(),
            null => [],
        };
    }

    /**
     * One record by id (to show the chosen label), or the ones whose columns contain the search text.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private static function search(Builder $query, array $columns, string $search, ?int $id): Builder
    {
        if ($id !== null) {
            return $query->whereKey($id);
        }

        return $query->when($search !== '', fn (Builder $b) => $b->where(function (Builder $w) use ($columns, $search) {
            foreach ($columns as $column) {
                $w->orWhere($column, 'like', '%'.$search.'%');
            }
        }));
    }
}
