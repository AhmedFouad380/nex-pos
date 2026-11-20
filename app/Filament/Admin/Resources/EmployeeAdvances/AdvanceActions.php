<?php

namespace App\Filament\Admin\Resources\EmployeeAdvances;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Payroll\Actions\GiveAdvance;
use App\Filament\Support\DomainGuard;
use App\Filament\Support\UserLocations;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Carbon;

/** "Give advance" modal, on the advances list and on an employee's page. */
final class AdvanceActions
{
    /** @param  ?Closure(): Contact  $employee  fixed employee (employee page), or null to pick one */
    public static function give(?Closure $employee = null): Action
    {
        return Action::make('giveAdvance')->label(__('Give advance'))->icon('heroicon-o-hand-raised')->color('warning')
            ->visible(function (): bool {
                $user = auth()->user();

                return $user !== null && $user->can('advances.create') && $user->can('payments.create');
            })
            ->schema(fn () => [
                Grid::make(2)->schema([
                    Select::make('contact_id')->label(__('Employee'))->required()->searchable()
                        ->options(fn () => Contact::query()->where('type', ContactType::Employee)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->visible($employee === null),
                    Select::make('location_id')->label(__('Location'))->required()->options(fn () => UserLocations::options())
                        ->default(fn () => ($employee ? $employee()->employeeProfile?->location_id : null) ?? UserLocations::first()),
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required(),
                    TextInput::make('installment_amount')->label(__('Monthly installment'))->numeric()->minValue(0.01)
                        ->helperText(__('Deducted from each payroll. Leave empty to deduct it all from the next one.')),
                    Select::make('payment_method_id')->label(__('Payment method'))->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->orderBy('sort')->pluck('name', 'id'))
                        ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id')),
                    DateTimePicker::make('advance_date')->label(__('Date'))->default(now())->required(),
                ]),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ])
            ->action(function (array $data) use ($employee) {
                abort_unless(array_key_exists((int) $data['location_id'], UserLocations::options()), 403);
                $advance = DomainGuard::run(fn () => app(GiveAdvance::class)->handle([
                    'contact_id' => $employee ? $employee()->id : (int) $data['contact_id'],
                    'location_id' => (int) $data['location_id'],
                    'amount' => $data['amount'],
                    'installment_amount' => $data['installment_amount'] ?? null,
                    'payment_method_id' => (int) $data['payment_method_id'],
                    'advance_date' => Carbon::parse($data['advance_date']),
                    'note' => $data['note'] ?? null,
                ]));
                Notification::make()->success()->title(__('Advance :number recorded', ['number' => $advance->number]))
                    ->actions([Action::make('print')->label(__('Print voucher'))->url(route('vouchers.print', $advance->payment_id), true)])
                    ->send();

            });
    }
}
