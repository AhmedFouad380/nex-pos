<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Models\DocumentSequence;
use App\Domain\Shared\Enums\DocumentType;
use Illuminate\Support\Facades\DB;

/**
 * Hands out gap-free, never-repeating document numbers (INV-00001, RV-00001, ...).
 *
 * Lookup order: sequence for (type, location) → global sequence for (type).
 * A missing global sequence is created on the fly with sensible defaults.
 * The row is locked for update, so concurrent cashiers never get the same number.
 */
final class DocumentNumberGenerator
{
    public function next(DocumentType $type, ?int $locationId = null): string
    {
        return DB::transaction(function () use ($type, $locationId): string {
            $sequence = $this->lockedSequence($type, $locationId);

            $number = $sequence->next_number;
            $sequence->increment('next_number');

            return $this->format($sequence, $number);
        });
    }

    /** Preview the next number without consuming it. */
    public function peek(DocumentType $type, ?int $locationId = null): string
    {
        $sequence = $this->findSequence($type, $locationId) ?? $this->defaultSequence($type);

        return $this->format($sequence, $sequence->next_number);
    }

    private function lockedSequence(DocumentType $type, ?int $locationId): DocumentSequence
    {
        $sequence = $this->findSequence($type, $locationId, lock: true);

        if ($sequence) {
            return $sequence;
        }

        DocumentSequence::query()->firstOrCreate(
            ['document_type' => $type, 'location_id' => null, 'year' => null],
            $this->defaultSequence($type)->only(['prefix', 'next_number', 'padding']),
        );

        return $this->findSequence($type, null, lock: true);
    }

    private function findSequence(DocumentType $type, ?int $locationId, bool $lock = false): ?DocumentSequence
    {
        $year = (int) now()->format('Y');

        $query = DocumentSequence::query()
            ->where('document_type', $type)
            ->where(fn ($q) => $q->whereNull('year')->orWhere('year', $year))
            ->where(fn ($q) => $q->whereNull('location_id')->when($locationId, fn ($q) => $q->orWhere('location_id', $locationId)))
            // most specific first: location before global, yearly before perpetual
            ->orderByRaw('location_id is null')
            ->orderByRaw('year is null');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function defaultSequence(DocumentType $type): DocumentSequence
    {
        return new DocumentSequence([
            'document_type' => $type,
            'prefix' => self::defaultPrefix($type),
            'next_number' => 1,
            'padding' => 5,
        ]);
    }

    private function format(DocumentSequence $sequence, int $number): string
    {
        return $sequence->prefix.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
    }

    public static function defaultPrefix(DocumentType $type): string
    {
        return self::DEFAULT_PREFIXES[$type->value];
    }

    /** @var array<value-of<DocumentType>, string> one entry per DocumentType case (enforced by a test) */
    public const DEFAULT_PREFIXES = [
        'sale' => 'INV-',
        'draft' => 'DR-',
        'quotation' => 'QT-',
        'sale_return' => 'SR-',
        'sales_order' => 'SO-',
        'purchase' => 'PU-',
        'purchase_return' => 'PR-',
        'purchase_order' => 'PO-',
        'purchase_requisition' => 'RQ-',
        'stock_adjustment' => 'ADJ-',
        'stock_transfer' => 'TR-',
        'stock_count' => 'CNT-',
        'receipt_voucher' => 'RV-',
        'payment_voucher' => 'PV-',
        'expense' => 'EXP-',
        'journal_entry' => 'JE-',
        'account_transfer' => 'AT-',
        'ledger_discount' => 'LD-',
        'contact' => 'C',
        'employee_advance' => 'ADV-',
        'payroll' => 'PAY-',
    ];
}
