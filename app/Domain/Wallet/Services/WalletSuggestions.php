<?php

namespace App\Domain\Wallet\Services;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;

/** Likely answers to "what was this for?", best first, for the employee to confirm with one tap. */
final class WalletSuggestions
{
    /** @return list<array{target: WalletTarget, id: int, label: string, reason: string}> */
    public function for(WalletTransaction $transaction): array
    {
        $suggestions = [];
        $phone = $transaction->counterparty_phone;

        // the sender's number is on a contact
        if ($phone !== null) {
            $last10 = substr($phone, -10);
            $contacts = Contact::query()->where('is_active', true)
                ->where(fn (Builder $q) => $q->where('mobile', 'like', '%'.$last10)->orWhere('alt_mobile', 'like', '%'.$last10))
                ->limit(3)->get();
            foreach ($contacts as $contact) {
                $suggestions[] = ['target' => WalletTarget::Contact, 'id' => $contact->id,
                    'label' => $contact->name.' ('.$contact->type->getLabel().')', 'reason' => __('wallet.suggest.same_phone')];
            }
        }

        // an open invoice of the branch for exactly this amount, from the last few days
        if ($transaction->direction === PaymentDirection::In && $transaction->amount !== null) {
            $sales = Sale::query()->where('status', SaleStatus::Final)->whereNull('voided_at')
                ->where('location_id', $transaction->location_id)->where('due_total', '>', 0)
                ->where('due_total', $transaction->amount)
                ->where('sale_date', '>=', ($transaction->occurred_at ?? now())->copy()->subDays(3))
                ->with('customer')->latest('sale_date')->limit(3)->get();
            foreach ($sales as $sale) {
                $suggestions[] = ['target' => WalletTarget::Sale, 'id' => $sale->id,
                    'label' => $sale->invoice_no.' — '.$sale->customer?->name, 'reason' => __('wallet.suggest.same_amount')];
            }
        }

        return $suggestions;
    }
}
