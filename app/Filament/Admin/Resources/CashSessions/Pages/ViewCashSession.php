<?php

namespace App\Filament\Admin\Resources\CashSessions\Pages;

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\CashSessions\CashSessionResource;
use App\Support\DomainException;
use Filament\Actions\Action;
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

        return $record instanceof CashSession ? $record->loadMissing(['cashRegister.location', 'user', 'closer', 'movements.creator'])
            : throw new \LogicException('Expected a cash session.');
    }

    public function summary(): array
    {
        return app(CashSessionSummary::class)->summarize($this->session());
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
            ActivityHistoryAction::make(),
        ];
    }
}
