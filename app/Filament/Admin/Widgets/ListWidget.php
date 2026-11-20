<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\DashboardScope;
use Filament\Widgets\Widget;

/** Small "top N" list on the dashboard: a heading, rows of label / value, and a link to the full report. */
abstract class ListWidget extends Widget
{
    use DashboardScope;

    protected string $view = 'filament.admin.widgets.list';

    protected int|string|array $columnSpan = 1;

    abstract public function heading(): string;

    /** @return list<array{label: string, value: string, hint?: ?string, danger?: bool}> */
    abstract public function items(): array;

    public function moreUrl(): ?string
    {
        return null;
    }

    public function emptyText(): string
    {
        return __('Nothing to show.');
    }
}
