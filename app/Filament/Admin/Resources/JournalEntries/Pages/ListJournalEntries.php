<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntries extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New journal entry'))];
    }
}
