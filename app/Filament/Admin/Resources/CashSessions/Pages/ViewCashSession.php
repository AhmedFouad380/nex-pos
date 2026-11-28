<?php

namespace App\Filament\Admin\Resources\CashSessions\Pages;

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Actions\ReceiveShiftCash;
use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\BranchCash;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Finance\Models\Payment;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\CashSessions\CashSessionResource;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\DomainGuard;
use App\Support\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** X report while open, Z report once closed. A manager can close someone else's shift. */
class ViewCashSession extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CashSessionResource::class;

    protected string $view = 'filament.admin.cash-session';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(CashSessionResource::canView($this->record), 403);
    }

    public function getTitle(): string
    {
        return __('Shift').' — '.$this->session()->user->name;
    }

    public function session(): CashSession
    {
        $record = $this->getRecord();

        return $record instanceof CashSession ? $record->loadMissing(['cashRegister.location', 'user', 'closer', 'movements.creator', 'receiver', 'receivedAccount', 'receiptTransfer'])
            : throw new \LogicException('Expected a cash session.');
    }

    public function summary(): array
    {
        return app(CashSessionSummary::class)->summarize($this->session());
    }

    /** @return list<array{payment: Payment, kind: string}> */
    public function operations(): array
    {
        return app(CashSessionSummary::class)->operations($this->session());
    }

    private function branchBox(): ?int
    {
        $register = $this->session()->cashRegister;

        return $register->cash_account_id ?? app(BranchCash::class)->boxFor($register->location_id);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
            Action::make('close')->label(__('Close shift'))->icon('heroicon-o-lock-closed')->color('danger')
                ->visible(fn () => $this->session()->status === CashSessionStatus::Open
                    && (auth()->user()?->can('cash_register.close_others') || ($this->session()->user_id === auth()->id() && auth()->user()?->can('cash_register.close'))))
                ->schema([
                    TextInput::make('counted')->label(__('Counted cash'))->numeric()->minValue(0)->required()
                        ->default(fn () => (float) $this->summary()['expected_cash']),
                    TextInput::make('card_slips')->label(__('Card slips'))->numeric()->integer()->minValue(0)->default(0),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->action(function (array $data) {
                    try {
                        app(CloseCashSession::class)->handle($this->session(), $data['counted'], [], (int) $data['card_slips'], note: $data['note'] ?? null);
                        Notification::make()->success()->title(__('Shift closed'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                    $this->record = $this->resolveRecord($this->session()->getKey());
                }),
            Action::make('receive')->label(__('Receive the cash'))->icon('heroicon-o-inbox-arrow-down')->color('success')
                ->visible(fn () => $this->session()->awaitsReceipt() && auth()->user()?->can('cash_register.close_others'))
                ->modalDescription(fn () => __('Counted at closing: :amount. The amount taken moves from the branch box to the box you choose; the float stays in the drawer.',
                    ['amount' => number_format((float) $this->session()->counted_cash, 2)]))
                ->schema([
                    TextInput::make('amount')->label(__('Amount received'))->numeric()->minValue(0)->required()
                        ->default(fn () => (float) $this->session()->counted_cash),
                    TextInput::make('kept_float')->label(__('Float left in the drawer'))->numeric()->minValue(0)->default(0),
                    Select::make('to_account_id')->label(__('Into box'))->searchable()->required()
                        ->options(fn () => collect(AccountOptions::money())->map(fn (array $group) => array_diff_key($group, [$this->branchBox() => true]))->all())
                        ->default(fn () => CashSession::query()->whereNotNull('received_account_id')
                            ->where('received_by', auth()->id())->latest('received_at')->value('received_account_id')),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->action(function (array $data) {
                    DomainGuard::run(fn () => app(ReceiveShiftCash::class)->handle($this->session(), $data['amount'], (int) $data['to_account_id'],
                        $data['kept_float'] ?? 0, $data['note'] ?? null));
                    Notification::make()->success()->title(__('Shift cash received'))->send();
                    $this->record = $this->resolveRecord($this->session()->getKey());
                }),
            Action::make('cancelReceipt')->label(__('Cancel the receipt'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn () => $this->session()->received_at !== null && auth()->user()?->can('cash_register.close_others'))
                ->requiresConfirmation()->modalDescription(__('The transfer is voided and the shift waits for its receipt again.'))
                ->action(function () {
                    DomainGuard::run(fn () => app(ReceiveShiftCash::class)->cancel($this->session()));
                    Notification::make()->success()->title(__('Receipt cancelled'))->send();
                    $this->record = $this->resolveRecord($this->session()->getKey());
                }),
            ActivityHistoryAction::make(),
        ];
    }
}
