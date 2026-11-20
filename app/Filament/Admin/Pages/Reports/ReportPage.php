<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Domain\Reporting\Data\ReportFilter;
use App\Filament\NavigationGroup;
use App\Filament\Support\UserLocations;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base of every report: period / location filters, summary cards, one or more tables, print and
 * Excel (CSV) export. A report only says what to show:
 *
 *   tables(): list<array{title?: string, columns: array<string, array{0: string, 1?: string}>, rows: list<array>, totals?: array}>
 *       column type (index 1): text (default) | money | qty | int | percent
 *   cards():  list<array{0: string, 1: string|int|float|null, 2?: string type, 3?: string color}>
 */
abstract class ReportPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Reports;

    protected string $view = 'filament.admin.report';

    /** Permission needed to open the report. */
    protected static string $permission;

    /** Which standard filters the report uses. */
    protected static bool $usesPeriod = true;

    protected static bool $usesLocation = true;

    public ?string $from = null;

    public ?string $to = null;

    public ?string $location = null;

    /** Report-specific "group by" choice (see groupOptions()). */
    public ?string $group = null;

    /** Report-specific second choice (see optionChoices()). */
    public ?string $option = null;

    /** Report-specific checkbox (see flagLabel()). */
    public bool $flag = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(static::$permission);
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->group = array_key_first($this->groupOptions());
        $this->option = array_key_first($this->optionChoices());
    }

    /** @return list<array<string, mixed>> */
    abstract public function tables(): array;

    /** @return list<array<int, mixed>> */
    public function cards(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function groupOptions(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function optionChoices(): array
    {
        return [];
    }

    public function optionLabel(): string
    {
        return '';
    }

    public function flagLabel(): ?string
    {
        return null;
    }

    public function usesPeriod(): bool
    {
        return static::$usesPeriod;
    }

    public function usesLocation(): bool
    {
        return static::$usesLocation;
    }

    /** @return array<int, string> */
    public function locationOptions(): array
    {
        return UserLocations::options();
    }

    public function filter(): ReportFilter
    {
        return new ReportFilter(
            $this->from ? Carbon::parse($this->from) : now()->startOfMonth(),
            $this->to ? Carbon::parse($this->to) : now(),
            $this->locationIds(),
        );
    }

    /** @return list<int>|null the chosen location, or the user's locations (null = all, for users who see every location) */
    public function locationIds(): ?array
    {
        $allowed = array_keys($this->locationOptions());
        if (filled($this->location)) {
            abort_unless(in_array((int) $this->location, $allowed, true), 403);

            return [(int) $this->location];
        }
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->can('locations.access_all')) ? null : $allowed;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label(__('Export to Excel'))->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn () => $this->export()),
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
        ];
    }

    /** CSV with a BOM so Excel opens Arabic text correctly. */
    public function export(): StreamedResponse
    {
        $tables = $this->tables();
        $name = Str::slug(static::class === self::class ? 'report' : class_basename(static::class)).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($tables) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($this->cards() as $card) {
                fputcsv($out, [$card[0], $card[1]]);
            }
            foreach ($tables as $table) {
                fputcsv($out, []);
                if (! empty($table['title'])) {
                    fputcsv($out, [$table['title']]);
                }
                fputcsv($out, array_map(fn ($c) => $c[0], $table['columns']));
                foreach ([...$table['rows'], ...(isset($table['totals']) ? [$table['totals']] : [])] as $row) {
                    fputcsv($out, array_map(fn ($key) => $row[$key] ?? '', array_keys($table['columns'])));
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Sums the given columns of the rows, for a totals line. @param list<array<string, mixed>> $rows */
    protected static function totals(array $rows, array $columns, string $labelKey = 'label'): array
    {
        $totals = [$labelKey => __('Total')];
        foreach ($columns as $column) {
            $totals[$column] = number_format(array_sum(array_map(fn ($r) => (float) ($r[$column] ?? 0), $rows)), 2, '.', '');
        }

        return $totals;
    }

    public static function format(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => number_format((float) $value, 2),
            'qty' => rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.'),
            'int' => number_format((int) $value),
            'percent' => number_format((float) $value, 1).'%',
            default => (string) $value,
        };
    }
}
