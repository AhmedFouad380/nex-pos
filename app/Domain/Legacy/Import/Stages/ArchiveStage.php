<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use Illuminate\Support\Facades\DB;

/**
 * Keeps every row of every table, as it was, in `legacy_records` — the guarantee that nothing in the old
 * database is lost, including history, login logs and settings that have no place in the new system.
 */
final class ArchiveStage implements Stage
{
    private const BATCH = 500;

    /** Bytes per insert — stays under small `max_allowed_packet` settings (MariaDB defaults to 1 MB). */
    private const BATCH_BYTES = 400_000;

    public function name(): string
    {
        return 'archive';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $total = array_sum(array_map(fn ($t) => $package->count($t), $package->tables()));
        $done = 0;

        foreach ($package->tables() as $table) {
            $pk = $package->primaryKey($table);
            $seen = [];
            $batch = [];
            $bytes = 0;
            foreach ($package->rows($table) as $row) {
                $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($pk !== []) {
                    $key = ImportContext::key(...array_map(fn ($c) => $row[$c] ?? '', $pk));
                } else {
                    // no primary key: the row itself is the key; identical rows are numbered so none is dropped
                    $hash = sha1((string) $json);
                    $seen[$hash] = ($seen[$hash] ?? 0) + 1;
                    $key = $hash.'#'.$seen[$hash];
                }
                $batch[] = ['source_table' => $table, 'record_key' => mb_substr($key, 0, 191), 'data' => $json, 'legacy_import_id' => $context->import->id];
                $bytes += strlen((string) $json) + 250;
                if (count($batch) >= self::BATCH || $bytes >= self::BATCH_BYTES) {
                    $this->flush($batch);
                    $done += count($batch);
                    $batch = [];
                    $bytes = 0;
                    $context->progress($this->name(), $done, $total);
                }
            }
            $this->flush($batch);
            $done += count($batch);
            $context->count($this->name(), $table, $package->count($table));
        }
        $context->progress($this->name(), $done, $total, force: true);
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(array $rows): void
    {
        if ($rows !== []) {
            DB::table('legacy_records')->upsert($rows, ['source_table', 'record_key'], ['data', 'legacy_import_id']);
        }
    }
}
