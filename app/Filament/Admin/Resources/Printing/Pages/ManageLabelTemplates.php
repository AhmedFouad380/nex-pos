<?php

namespace App\Filament\Admin\Resources\Printing\Pages;

use App\Domain\Printing\Models\LabelTemplate;
use App\Filament\Admin\Resources\Printing\LabelTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLabelTemplates extends ManageRecords
{
    protected static string $resource = LabelTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->after(fn (LabelTemplate $record) => LabelTemplateResource::keepSingleDefault($record))];
    }
}
