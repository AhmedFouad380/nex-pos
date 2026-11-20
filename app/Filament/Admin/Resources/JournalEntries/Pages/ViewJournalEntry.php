<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Models\JournalEntry;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\DomainGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['lines.account', 'lines.contact', 'creator', 'reversedEntry']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverse')->label(__('Reverse entry'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn (JournalEntry $record) => $record->is_manual && $record->reversed_entry_id === null
                    && ! JournalEntry::query()->where('reversed_entry_id', $record->id)->exists()
                    && auth()->user()?->can('journal.manual'))
                ->requiresConfirmation()
                ->action(function (JournalEntry $record) {
                    $reversal = DomainGuard::run(fn () => app(RecordJournalEntry::class)->reverse($record));
                    Notification::make()->success()->title(__('Saved'))->send();
                    $this->redirect(JournalEntryResource::getUrl('view', ['record' => $reversal]));
                }),
            ActivityHistoryAction::make(),
        ];
    }
}
