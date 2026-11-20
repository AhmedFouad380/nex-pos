<?php

namespace App\Filament\Admin\Support;

use App\Domain\CRM\Actions\CreateLedgerDiscount;
use App\Domain\CRM\Actions\RecordOpeningBalance;
use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Models\Contact;
use App\Support\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

final class ContactPageActions
{
    public static function openingBalance(BalanceDirection $default): Action
    {
        return Action::make('openingBalance')
            ->label(__('Add opening balance'))->icon('heroicon-o-flag')
            ->visible(fn () => auth()->user()?->can('accounts.manage'))
            ->schema([
                TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required(),
                Select::make('direction')->label(__('Balance is'))->options(BalanceDirection::class)->default($default)->required(),
                DatePicker::make('as_of')->label(__('As of'))->default(now())->required(),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ])
            ->action(fn (Contact $record, array $data) => self::run(fn () => app(RecordOpeningBalance::class)->handle(
                $record, (string) $data['amount'], BalanceDirection::from($data['direction'] instanceof BalanceDirection ? $data['direction']->value : $data['direction']),
                (string) $data['as_of'], note: $data['note'] ?? null)));
    }

    public static function ledgerDiscount(BalanceDirection $default): Action
    {
        return Action::make('ledgerDiscount')
            ->label(__('Discount on account'))->icon('heroicon-o-receipt-percent')
            ->visible(fn () => auth()->user()?->can('ledger_discounts.manage'))
            ->schema([
                TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required(),
                Select::make('direction')->label(__('Lowers'))->required()->default($default)
                    ->options([
                        BalanceDirection::Receivable->value => __('What they owe us'),
                        BalanceDirection::Payable->value => __('What we owe them'),
                    ]),
                DatePicker::make('date')->label(__('Date'))->default(now())->required(),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ])
            ->action(fn (Contact $record, array $data) => self::run(fn () => app(CreateLedgerDiscount::class)->handle(
                $record, (string) $data['amount'], BalanceDirection::from($data['direction'] instanceof BalanceDirection ? $data['direction']->value : $data['direction']),
                (string) $data['date'], $data['note'] ?? null)));
    }

    private static function run(\Closure $callback): void
    {
        try {
            $callback();
            Notification::make()->success()->title(__('Saved'))->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }
}
