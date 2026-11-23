<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\ParsedMessage;
use App\Domain\Wallet\Models\WalletMessageTemplate;
use App\Support\DomainException;
use Carbon\CarbonImmutable;

/**
 * Turns a template written as plain message text with {placeholders} into a regular expression, so the
 * wording can be maintained from the admin panel without writing regex:
 *
 *   تم استلام مبلغ {amount} جنيه من {phone}؛ المسجل بإسم {name} ... رقم العملية: {reference}
 *
 * Whitespace in the template matches any whitespace (or none), as does the space around punctuation; {*} skips any text (ads, links).
 */
final class TemplateCompiler
{
    private const NUMBER = '[0-9][0-9,]*(?:\.[0-9]+)?';

    private const PHONE = '(?:\+?20|0)?1[0-9]{9}';

    /** placeholder => regex of its value */
    public const PLACEHOLDERS = [
        'amount' => self::NUMBER,
        'fee' => self::NUMBER,
        'balance' => self::NUMBER,
        'phone' => self::PHONE,
        'wallet' => self::PHONE,
        'name' => '.+?',
        'datetime' => '[0-9][0-9:\-\/. ]*[0-9]',
        'reference' => '[A-Za-z0-9\-]{4,}',
        '*' => '.*?',
    ];

    public function compile(string $template): string
    {
        $parts = preg_split('/(\{[a-z*]+\})/u', self::normalize($template), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        $seen = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z*]+)\}$/', $part, $m)) {
                $name = $m[1];
                if (! array_key_exists($name, self::PLACEHOLDERS)) {
                    throw new DomainException(__('wallet.errors.unknown_placeholder', ['name' => $part]));
                }
                if ($name === '*') {
                    $regex .= self::PLACEHOLDERS['*'];

                    continue;
                }
                if (isset($seen[$name])) {
                    throw new DomainException(__('wallet.errors.repeated_placeholder', ['name' => $part]));
                }
                $seen[$name] = true;
                $regex .= '(?<'.$name.'>'.self::PLACEHOLDERS[$name].')';

                continue;
            }
            // literal text: any run of whitespace is optional whitespace, and so is the space around punctuation
            // (". رصيدك" in the template matches ".\nرصيدك" and ".رصيدك" in the message)
            $words = preg_split('/\s+/u', (string) preg_replace('/([.:؛،,])/u', ' $1 ', $part)) ?: [];
            $regex .= implode('\s*', array_map(fn (string $w) => preg_quote($w, '/'), $words));
        }
        if (! isset($seen['amount'])) {
            throw new DomainException(__('wallet.errors.amount_required'));
        }

        return '/'.$regex.'/us';
    }

    public function parse(WalletMessageTemplate $template, string $body): ?ParsedMessage
    {
        if (! preg_match($this->compile($template->template), self::normalize($body), $m)) {
            return null;
        }
        $value = fn (string $key) => isset($m[$key]) && $m[$key] !== '' ? trim($m[$key]) : null;
        $number = fn (string $key) => $value($key) !== null ? rtrim(str_replace(',', '', (string) $value($key)), '.') : null;

        return new ParsedMessage(
            template: $template,
            amount: (string) $number('amount'),
            fee: $number('fee') ?? '0',
            balance: $number('balance'),
            counterpartyPhone: self::phone($value('phone')),
            counterpartyName: $value('name'),
            walletPhone: self::phone($value('wallet')),
            reference: $value('reference'),
            occurredAt: $this->date($value('datetime'), $template->date_format),
        );
    }

    /** 01012345678 from +201012345678 / 201012345678 / 1012345678. */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $phone);

        return '0'.substr($digits, -10);
    }

    /** Arabic-Indic digits → Latin, Arabic decimal/thousands separators → . and , — messages use either. */
    public static function normalize(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => ',', "\u{200F}" => '', "\u{200E}" => '', "\r" => '',
        ]);
    }

    private function date(?string $text, string $format): ?CarbonImmutable
    {
        if ($text === null) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat($format, $text);
        } catch (\Throwable) {
            return null;
        }

        return $date ?: null;
    }
}
