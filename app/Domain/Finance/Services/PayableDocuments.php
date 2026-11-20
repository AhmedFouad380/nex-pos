<?php

namespace App\Domain\Finance\Services;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Expense;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Which documents a payment may settle:
 *   money IN  (from the contact) → sales, receivable opening balances, purchase returns (supplier refunds)
 *   money OUT (to the contact)   → purchases, payable opening balances, sale returns (customer refunds), expenses
 */
final class PayableDocuments
{
    /** class => [contact column, date column, direction] */
    public const TYPES = [
        Sale::class => ['customer_id', 'sale_date', PaymentDirection::In],
        PurchaseReturn::class => ['supplier_id', 'return_date', PaymentDirection::In],
        Purchase::class => ['supplier_id', 'purchase_date', PaymentDirection::Out],
        SaleReturn::class => ['customer_id', 'return_date', PaymentDirection::Out],
        Expense::class => ['contact_id', 'expense_date', PaymentDirection::Out],
        ContactOpeningBalance::class => ['contact_id', 'as_of_date', null], // direction depends on the row
    ];

    public static function directionFor(Model $document): PaymentDirection
    {
        if ($document instanceof ContactOpeningBalance) {
            return $document->direction === BalanceDirection::Receivable ? PaymentDirection::In : PaymentDirection::Out;
        }

        return self::TYPES[$document::class][2];
    }

    public static function contactIdOf(Model $document): ?int
    {
        return $document->getAttribute(self::TYPES[$document::class][0]);
    }

    /** Open (due > 0, not voided) documents of a contact a payment in $direction can settle, oldest first. */
    public static function open(Contact $contact, PaymentDirection $direction): Collection
    {
        $documents = collect();

        foreach (self::TYPES as $class => [$contactColumn, $dateColumn, $docDirection]) {
            if ($docDirection !== null && $docDirection !== $direction) {
                continue;
            }
            /** @var Builder<Model> $query */
            $query = $class::query()
                ->where($contactColumn, $contact->id)
                ->whereNull('voided_at')
                ->where('due_total', '>', 0);

            if ($class === Sale::class) {
                $query->where('status', SaleStatus::Final);
            }
            if ($class === ContactOpeningBalance::class) {
                $query->where('direction', $direction === PaymentDirection::In ? BalanceDirection::Receivable : BalanceDirection::Payable);
            }

            $documents = $documents->concat($query->get()->map(fn (Model $m) => ['date' => $m->getAttribute($dateColumn), 'model' => $m]));
        }

        return $documents->sortBy('date')->pluck('model')->values();
    }
}
