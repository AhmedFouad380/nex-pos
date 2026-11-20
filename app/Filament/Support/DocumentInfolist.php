<?php

namespace App\Filament\Support;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

/** Totals + payments sections shared by purchase / sale document pages. */
final class DocumentInfolist
{
    public static function totals(): Section
    {
        return Section::make(__('Totals'))->columnSpan(2)->columns(2)->schema([
            TextEntry::make('subtotal')->label(__('Subtotal'))->numeric(2),
            TextEntry::make('discount_amount')->label(__('Discount'))->numeric(2),
            TextEntry::make('tax_amount')->label(__('Tax'))->numeric(2),
            TextEntry::make('shipping_charges')->label(__('Shipping'))->numeric(2),
            // purchases only (sales have no such column)
            TextEntry::make('additional_costs_total')->label(__('Additional costs'))->numeric(2)
                ->visible(fn (Model $record) => (float) ($record->getAttributes()['additional_costs_total'] ?? 0) > 0),
            TextEntry::make('packing_charge')->label(__('Packing'))->numeric(2)
                ->visible(fn (Model $record) => (float) ($record->getAttributes()['packing_charge'] ?? 0) > 0),
            TextEntry::make('total')->label(__('Total'))->numeric(2)->weight('bold')->size('lg'),
            TextEntry::make('paid_total')->label(__('Paid'))->numeric(2)->color('success'),
            TextEntry::make('due_total')->label(__('Due'))->numeric(2)->color(fn ($state) => (float) $state > 0 ? 'danger' : null)->weight('bold'),
        ]);
    }

    public static function payments(): Section
    {
        return Section::make(__('Payments'))->columnSpan(2)->schema([
            RepeatableEntry::make('allocations')->hiddenLabel()
                ->table([TableColumn::make(__('Number')), TableColumn::make(__('Date')), TableColumn::make(__('Payment method')), TableColumn::make(__('Amount'))])
                ->schema([
                    TextEntry::make('payment.number')->color(fn ($record) => $record->payment->voided_at ? 'danger' : null)
                        ->belowContent(fn ($record) => $record->payment->voided_at ? __('Voided') : null),
                    TextEntry::make('payment.paid_at')->dateTime(),
                    TextEntry::make('payment.paymentMethod.name'),
                    TextEntry::make('amount')->numeric(2),
                ])
                ->placeholder(__('No payments yet.')),
        ]);
    }
}
