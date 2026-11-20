<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Support\Decimal;
use App\Support\DomainException;

/** What a contact owed us (receivable) or we owed them (payable) before using the system. Payable like an invoice. */
final class RecordOpeningBalance
{
    public function __construct(private readonly ContactLedger $ledger) {}

    public function handle(Contact $contact, string $amount, BalanceDirection $direction, string $asOf, ?int $locationId = null, ?string $note = null): ContactOpeningBalance
    {
        if (! Decimal::of($amount)->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }

        $balance = $contact->openingBalances()->create([
            'location_id' => $locationId,
            'direction' => $direction,
            'amount' => Decimal::toDb($amount),
            'due_total' => Decimal::toDb($amount),
            'payment_status' => PaymentStatus::Due,
            'as_of_date' => $asOf,
            'note' => $note,
            'created_by' => auth()->id(),
        ]);
        $this->ledger->refresh($contact);
        app(Posting::class)->sync($balance);

        return $balance;
    }
}
