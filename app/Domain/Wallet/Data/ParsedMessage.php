<?php

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Models\WalletMessageTemplate;
use Carbon\CarbonImmutable;

/** Values read out of a wallet message by a template. Amounts are plain decimal strings. */
final readonly class ParsedMessage
{
    public function __construct(
        public WalletMessageTemplate $template,
        public string $amount,
        public string $fee = '0',
        public ?string $balance = null,
        public ?string $counterpartyPhone = null,
        public ?string $counterpartyName = null,
        public ?string $walletPhone = null,
        public ?string $reference = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}

    /** @return array<string, string|null> for the "test template" screen */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'fee' => $this->fee,
            'balance' => $this->balance,
            'phone' => $this->counterpartyPhone,
            'name' => $this->counterpartyName,
            'wallet' => $this->walletPhone,
            'reference' => $this->reference,
            'datetime' => $this->occurredAt?->format('Y-m-d H:i'),
        ];
    }
}
