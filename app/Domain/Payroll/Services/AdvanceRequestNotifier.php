<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\AdvanceRequest;
use App\Models\User;
use App\Support\BellNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Bell notifications of the advance requests: the approvers of the branch when one is made, the payers once
 * it is approved, and the employee who asked at every decision.
 */
final class AdvanceRequestNotifier
{
    public function requested(AdvanceRequest $request): void
    {
        $request->loadMissing('contact');
        $notification = Notification::make()->icon('heroicon-o-hand-raised')->iconColor('warning')
            ->title(__('payroll.requests.new', ['amount' => $this->money($request->amount), 'name' => $request->contact->name]))
            ->body($request->reason ? __('payroll.requests.new_body', ['reason' => $request->reason]) : null)
            ->actions([$this->open()]);

        $this->send($notification, $this->staff($request, 'advances.approve'));
    }

    public function approved(AdvanceRequest $request): void
    {
        $request->loadMissing('contact');
        $amount = $this->money($request->payableAmount());

        $this->send(Notification::make()->icon('heroicon-o-check-badge')->iconColor('success')
            ->title(__('payroll.requests.approved', ['amount' => $amount]))->body($request->decision_note), $this->requester($request));

        $this->send(Notification::make()->icon('heroicon-o-banknotes')->iconColor('info')
            ->title(__('payroll.requests.approved_pay', ['amount' => $amount, 'name' => $request->contact->name]))
            ->actions([$this->open()]), $this->staff($request, 'advances.create'));
    }

    public function rejected(AdvanceRequest $request): void
    {
        $this->send(Notification::make()->icon('heroicon-o-x-circle')->iconColor('danger')
            ->title(__('payroll.requests.rejected', ['amount' => $this->money($request->amount)]))->body($request->decision_note),
            $this->requester($request));
    }

    public function paid(AdvanceRequest $request): void
    {
        $this->send(Notification::make()->icon('heroicon-o-banknotes')->iconColor('success')
            ->title(__('payroll.requests.paid', ['amount' => $this->money($request->payableAmount())])), $this->requester($request));
    }

    /** @param iterable<User> $users */
    private function send(Notification $notification, iterable $users): void
    {
        BellNotification::send($notification, $users);
    }

    /** @return list<User> */
    private function requester(AdvanceRequest $request): array
    {
        $user = $request->requested_by ? User::query()->find($request->requested_by) : null;

        return $user ? [$user] : [];
    }

    /** @return iterable<User> active users with the permission in the request's branch (not the employee it is for) */
    private function staff(AdvanceRequest $request, string $permission): iterable
    {
        return User::query()->active()->get()
            ->filter(fn (User $user) => $user->can($permission) && ! $request->isFor($user)
                && ($request->location_id === null || $user->accessibleLocations()->whereKey($request->location_id)->exists()));
    }

    private function open(): Action
    {
        return Action::make('open')->label(__('payroll.requests.open'))
            ->url(route('filament.admin.resources.advance-requests.index'))->markAsRead();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) (string) $amount, 2);
    }
}
