<?php

namespace App\Filament\Admin\Resources\Settings\Pages;

use App\Filament\Admin\Resources\Settings\DocumentSequenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentSequences extends ManageRecords
{
    protected static string $resource = DocumentSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Separate numbering for a branch or year'))];
    }
}
