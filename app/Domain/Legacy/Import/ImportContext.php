<?php

namespace App\Domain\Legacy\Import;

use App\Domain\Legacy\Models\LegacyIdMap;
use App\Domain\Legacy\Models\LegacyImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * State shared by the stages of one import: the old → new id map (cached per table), the options chosen
 * on the page, counters, warnings and progress (saved on the LegacyImport row so the page can show it).
 */
final class ImportContext
{
    /** @var array<string, array<string, array{0: string, 1: int}>> legacy table => key => [new type, new id] */
    private array $map = [];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    private float $lastSave = 0;

    public function __construct(public readonly LegacyImport $import) {}

    public static function key(mixed ...$parts): string
    {
        return implode('|', array_map(fn ($p) => is_float($p) ? rtrim(rtrim(number_format($p, 4, '.', ''), '0'), '.') : (string) $p, $parts));
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->import->options ?? [], $key, $default);
    }

    public function newId(string $legacyTable, string $key): ?int
    {
        return $this->entries($legacyTable)[$key][1] ?? null;
    }

    public function newType(string $legacyTable, string $key): ?string
    {
        return $this->entries($legacyTable)[$key][0] ?? null;
    }

    public function remember(string $legacyTable, string $key, Model $model): void
    {
        $type = $model->getMorphClass();
        $id = (int) $model->getKey();
        if (($this->map[$legacyTable][$key] ?? null) === [$type, $id]) {
            return;
        }
        LegacyIdMap::query()->updateOrCreate(['legacy_table' => $legacyTable, 'legacy_key' => $key],
            ['new_type' => $type, 'new_id' => $id, 'migrated_at' => now()]);
        $this->map[$legacyTable][$key] = [$type, $id];
    }

    /** @return array<string, array{0: string, 1: int}> */
    private function entries(string $legacyTable): array
    {
        if (! isset($this->map[$legacyTable])) {
            $this->map[$legacyTable] = [];
            foreach (DB::table('legacy_id_map')->where('legacy_table', $legacyTable)->get(['legacy_key', 'new_type', 'new_id']) as $row) {
                $this->map[$legacyTable][$row->legacy_key] = [$row->new_type, (int) $row->new_id];
            }
        }

        return $this->map[$legacyTable];
    }

    public function count(string $stage, string $what, int $by = 1): void
    {
        $this->counts[$stage][$what] = ($this->counts[$stage][$what] ?? 0) + $by;
    }

    public function warn(string $message): void
    {
        if (count($this->warnings) < 2000) {
            $this->warnings[] = $message;
        }
    }

    public function progress(string $stage, int $done, int $total, bool $force = false): void
    {
        if (! $force && microtime(true) - $this->lastSave < 2) {
            return; // don't hammer the database
        }
        $this->lastSave = microtime(true);
        $progress = $this->import->progress ?? [];
        $progress[$stage] = ['done' => $done, 'total' => $total];
        $this->import->forceFill(['stage' => $stage, 'progress' => $progress, 'report' => $this->report()])->save();
    }

    /** @return array{counts: array<string, array<string, int>>, warnings: list<string>} */
    public function report(): array
    {
        return ['counts' => $this->counts, 'warnings' => $this->warnings] + ($this->import->report ?? []);
    }
}
