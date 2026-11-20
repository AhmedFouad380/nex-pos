<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use App\Filament\Support\UserLocations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateJournalEntry extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = JournalEntryResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        if (filled($data['location_id'] ?? null)) {
            abort_unless(array_key_exists((int) $data['location_id'], UserLocations::options()), 403);
        }

        return $this->rescueDomain(fn () => app(RecordJournalEntry::class)->handle(
            Carbon::parse($data['entry_date']),
            $data['description'],
            array_values(array_map(fn (array $l) => [
                'account_id' => (int) $l['account_id'], 'debit' => $l['debit'] ?? null, 'credit' => $l['credit'] ?? null, 'memo' => $l['memo'] ?? null,
            ], $data['lines'])),
            filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
