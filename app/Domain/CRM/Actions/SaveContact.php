<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Creates / updates a contact. On create, an opening balance can be recorded in the same step. */
final class SaveContact
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly RecordOpeningBalance $openingBalance,
    ) {}

    public function handle(array $data, ?Contact $contact = null): Contact
    {
        return DB::transaction(function () use ($data, $contact) {
            $isNew = $contact === null;
            $contact ??= new Contact(['created_by' => auth()->id()]);
            $attributes = Arr::except($data, ['opening_balance', 'opening_balance_direction', 'opening_balance_date', 'user_ids']);
            if (blank($attributes['code'] ?? null)) {
                $attributes['code'] = $contact->code ?? $this->numbers->next(DocumentType::Contact);
            }
            $contact->fill($attributes)->save();

            if (array_key_exists('user_ids', $data)) {
                $contact->users()->sync($data['user_ids'] ?? []);
            }

            if ($isNew && Decimal::of($data['opening_balance'] ?? 0)->isPositive()) {
                $type = $contact->type;
                $direction = $data['opening_balance_direction'] ?? ($type === ContactType::Supplier ? BalanceDirection::Payable : BalanceDirection::Receivable);
                $this->openingBalance->handle($contact, (string) $data['opening_balance'],
                    $direction instanceof BalanceDirection ? $direction : BalanceDirection::from($direction),
                    $data['opening_balance_date'] ?? now()->toDateString());
            }

            return $contact->refresh();
        });
    }
}
