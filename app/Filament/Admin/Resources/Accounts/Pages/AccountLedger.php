<?php

namespace App\Filament\Admin\Resources\Accounts\Pages;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Services\GeneralLedger;
use App\Filament\Admin\Resources\Accounts\AccountResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Support\FiscalYear;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Account ledger (دفتر الأستاذ): every journal line of an account and its sub-accounts, with a running balance. */
class AccountLedger extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AccountResource::class;

    protected string $view = 'filament.admin.account-ledger';

    public ?string $from = null;

    public ?string $to = null;

    public function mount(int|string $record): void
    {
        abort_unless(auth()->user()?->can('accounts.view'), 403);
        $this->record = $this->resolveRecord($record);
        $this->from = FiscalYear::range()[0]->toDateString(); // the year chosen in the top bar
        $this->to = FiscalYear::reportEnd()->toDateString();
    }

    public function account(): Account
    {
        $record = $this->getRecord();

        return $record instanceof Account ? $record : throw new \LogicException('Ledger needs an account.');
    }

    public function getTitle(): string
    {
        return __('Ledger').' — '.$this->account()->code.' '.$this->account()->name;
    }

    /** @return array{opening: string, rows: Collection<int, \stdClass>, debit: string, credit: string, closing: string} */
    public function ledger(): array
    {
        return app(GeneralLedger::class)->statement(
            $this->account(),
            $this->from ? Carbon::parse($this->from) : null,
            $this->to ? Carbon::parse($this->to) : null,
        );
    }

    public function entryUrl(int $entryId): string
    {
        return JournalEntryResource::getUrl('view', ['record' => $entryId]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
            Action::make('back')->label(__('Back'))->color('gray')->url(AccountResource::getUrl()),
        ];
    }
}
