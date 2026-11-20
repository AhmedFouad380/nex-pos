<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Data\JournalDraft;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the general ledger in step with documents. Entries are never edited or deleted:
 * when a document's entry is no longer right (voided, received, closed…) it is reversed
 * and, if the document still needs one, a fresh entry is posted.
 */
final class Posting
{
    public function __construct(
        private readonly PostingRules $rules,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /** Makes the document's live journal entry match what the posting rules say it should be. Idempotent. */
    public function sync(Model $document): ?JournalEntry
    {
        if (! PostingRules::supports($document)) {
            return null;
        }

        return DB::transaction(function () use ($document) {
            $draft = $this->rules->draft($document->refresh());
            $live = $this->liveEntries($document)->with('lines')->get();

            $wanted = $draft?->signature();
            $current = $live->first();
            if ($live->count() === 1 && $wanted !== null && $current !== null
                && JournalDraft::signatureOf($current->lines->map(fn ($l) => $l->only(['account_id', 'contact_id', 'debit', 'credit']))) === $wanted
                && $current->entry_date->isSameDay($draft->date)) {
                return $current; // already right
            }

            foreach ($live as $entry) {
                $this->reverse($entry, PostingRules::voidedAt($document) ?? now());
            }

            return $draft && $draft->lines() !== [] ? $this->save($draft, $document) : null;
        });
    }

    /** Hand-written entry (قيد يومية يدوي). */
    public function manual(JournalDraft $draft): JournalEntry
    {
        if (count($draft->lines()) < 2) {
            throw new DomainException(__('finance.errors.entry_needs_two_lines'));
        }

        return DB::transaction(fn () => $this->save($draft, null));
    }

    /** Posts the mirror image of an entry, dated $at; the original stays as it was. */
    public function reverse(JournalEntry $entry, ?CarbonInterface $at = null): JournalEntry
    {
        if ($entry->reversed_entry_id !== null || JournalEntry::query()->where('reversed_entry_id', $entry->id)->exists()) {
            throw new DomainException(__('finance.errors.already_reversed'));
        }

        return DB::transaction(function () use ($entry, $at) {
            $reversal = JournalEntry::query()->create([
                'number' => $this->numbers->next(DocumentType::JournalEntry, $entry->location_id),
                'entry_date' => ($at ?? now())->toDateString(),
                'description' => __('finance.posting.reversal', ['number' => $entry->number]),
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'location_id' => $entry->location_id,
                'is_manual' => $entry->is_manual,
                'reversed_entry_id' => $entry->id,
                'created_by' => auth()->id(),
            ]);
            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'entry_date' => $reversal->entry_date,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'contact_id' => $line->contact_id,
                    'location_id' => $line->location_id,
                    'memo' => $line->memo,
                ]);
            }

            return $reversal;
        });
    }

    /**
     * Entries of the document that are still in effect: not a reversal, and not reversed.
     *
     * @return Builder<JournalEntry>
     */
    public function liveEntries(Model $document): Builder
    {
        return JournalEntry::query()
            ->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
            ->whereNull('reversed_entry_id')
            ->whereNotExists(fn ($q) => $q->from('journal_entries as r')->whereColumn('r.reversed_entry_id', 'journal_entries.id'))
            ->orderBy('id');
    }

    private function save(JournalDraft $draft, ?Model $source): JournalEntry
    {
        if (! $draft->imbalance()->isZero()) {
            throw new DomainException(__('finance.errors.unbalanced', ['difference' => Decimal::toDb($draft->imbalance(), 2)]));
        }

        $entry = JournalEntry::query()->create([
            'number' => $this->numbers->next(DocumentType::JournalEntry, $draft->locationId),
            'entry_date' => $draft->date->toDateString(),
            'description' => mb_substr($draft->description, 0, 255),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'location_id' => $draft->locationId,
            'is_manual' => $source === null,
            'created_by' => auth()->id(),
        ]);
        foreach ($draft->lines() as $line) {
            $entry->lines()->create([...$line, 'entry_date' => $entry->entry_date, 'location_id' => $draft->locationId]);
        }

        return $entry;
    }
}
