<?php

namespace App\Filament\Admin\Resources\Settings\Pages;

use App\Filament\Admin\Resources\Settings\ActivityLogResource;
use Filament\Resources\Pages\ManageRecords;

class ListActivities extends ManageRecords
{
    protected static string $resource = ActivityLogResource::class;
}
