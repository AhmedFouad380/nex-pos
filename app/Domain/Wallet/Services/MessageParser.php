<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\ParsedMessage;
use App\Domain\Wallet\Models\WalletMessageTemplate;
use Illuminate\Support\Collection;

/** Reads a wallet message with the first active template (of that sender) that matches it. */
final class MessageParser
{
    public function __construct(private readonly TemplateCompiler $compiler) {}

    public function parse(string $body, ?string $sender = null): ?ParsedMessage
    {
        foreach ($this->templates($sender) as $template) {
            $parsed = $this->compiler->parse($template, $body);
            if ($parsed !== null && $parsed->amount !== '') {
                return $parsed;
            }
        }

        return null;
    }

    /** @return Collection<int, WalletMessageTemplate> */
    private function templates(?string $sender): Collection
    {
        return WalletMessageTemplate::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (WalletMessageTemplate $t) => blank($t->sender) || $sender === null
                || mb_strtolower(trim($t->sender)) === mb_strtolower(trim($sender)));
    }

    /**
     * Sender ids the phones should forward (all templates with one); empty = the phones forward nothing.
     *
     * @return list<string>
     */
    public static function senders(): array
    {
        return WalletMessageTemplate::query()->where('is_active', true)->whereNotNull('sender')
            ->distinct()->orderBy('sender')->pluck('sender')->values()->all();
    }
}
