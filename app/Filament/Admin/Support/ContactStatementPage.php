<?php

namespace App\Filament\Admin\Support;

use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Admin\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Resources\SaleReturns\SaleReturnResource;
use App\Filament\Admin\Resources\Sales\SaleResource;
use App\Support\FiscalYear;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Printable account statement (كشف حساب) with a date range and running balance. */
abstract class ContactStatementPage extends Page
{
    use InteractsWithRecord;

    protected string $view = 'filament.admin.contact-statement';

    public ?string $from = null;

    public ?string $to = null;

    public function mount(int|string $record): void
    {
        abort_unless(auth()->user()?->can('contacts.ledger'), 403);
        $this->record = $this->resolveRecord($record);
        $this->from = FiscalYear::range()[0]->toDateString(); // the year chosen in the top bar
        $this->to = FiscalYear::reportEnd()->toDateString();
    }

    public function getTitle(): string
    {
        return __('Account statement').' — '.$this->contact()->name;
    }

    public function contact(): Contact
    {
        $record = $this->getRecord();

        return $record instanceof Contact ? $record : throw new \LogicException('Statement needs a contact.');
    }

    /** @return array{opening: string, rows: Collection<int, \stdClass>, closing: string} */
    public function statement(): array
    {
        return app(ContactLedger::class)->statement(
            $this->contact(),
            $this->from ? Carbon::parse($this->from) : null,
            $this->to ? Carbon::parse($this->to) : null,
        );
    }

    public function documentUrl(string $kind, int $id): ?string
    {
        return match ($kind) {
            'purchase' => PurchaseResource::getUrl('view', ['record' => $id]),
            'purchase_return' => PurchaseReturnResource::getUrl('view', ['record' => $id]),
            'payment' => VoucherResource::urlFor($id),
            'sale' => SaleResource::getUrl('view', ['record' => $id]),
            'sale_return' => SaleReturnResource::getUrl('view', ['record' => $id]),
            'journal_entry' => JournalEntryResource::getUrl('view', ['record' => $id]),
            'payroll' => PayrollRunResource::getUrl('view', ['record' => $id]),
            default => null, // opening balances / discounts have no page of their own
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')
                ->alpineClickHandler('window.print()'),
            Action::make('back')->label(__('Back'))->color('gray')
                ->url(fn () => static::getResource()::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
