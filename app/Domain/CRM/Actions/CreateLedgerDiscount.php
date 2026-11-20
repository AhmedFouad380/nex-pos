<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\LedgerDiscount;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** A discount granted on the account statement: lowers what the customer owes (receivable) or what we owe a supplier (payable). */
final class CreateLedgerDiscount
{
    public function __construct(private readonly DocumentNumberGenerator $numbers, private readonly ContactLedger $ledger) {}

    public function handle(Contact $contact, string $amount, BalanceDirection $direction, ?string $date = null, ?string $note = null, ?int $locationId = null): LedgerDiscount
    {
        if (! Decimal::of($amount)->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }

        return DB::transaction(function () use ($contact, $amount, $direction, $date, $note, $locationId) {
            $discount = $contact->ledgerDiscounts()->create([
                'number' => $this->numbers->next(DocumentType::LedgerDiscount, $locationId),
                'location_id' => $locationId,
                'direction' => $direction,
                'date' => $date ?? now()->toDateString(),
                'amount' => Decimal::toDb($amount),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);
            $this->ledger->refresh($contact);

            app(Posting::class)->sync($discount);

            return $discount;
        });
    }

    public function void(LedgerDiscount $discount, string $reason): void
    {
        if ($discount->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }
        DB::transaction(function () use ($discount, $reason) {
            $discount->update(['voided_at' => now(), 'void_reason' => $reason]);
            $this->ledger->refresh($discount->contact);
            app(Posting::class)->sync($discount);
        });
    }
}
