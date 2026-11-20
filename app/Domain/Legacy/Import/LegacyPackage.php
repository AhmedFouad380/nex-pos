<?php

namespace App\Domain\Legacy\Import;

use App\Support\DomainException;
use Generator;
use ZipArchive;

/** A package written by `legacy:extract`: `manifest.json` + one `<table>.jsonl` per table. */
final class LegacyPackage
{
    /** @var array<string, mixed> */
    private array $manifest;

    /** @var array<string, list<array<string, mixed>>> small tables kept in memory */
    private array $cache = [];

    public function __construct(public readonly string $dir)
    {
        $file = $dir.'/manifest.json';
        if (! is_file($file)) {
            throw new DomainException(__('legacy.errors.bad_package'));
        }
        $this->manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function fromZip(string $zipPath, string $extractTo): self
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new DomainException(__('legacy.errors.bad_package'));
        }
        @mkdir($extractTo, 0775, true);
        $zip->extractTo($extractTo);
        $zip->close();

        return new self($extractTo);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->manifest['tables'] ?? []);
    }

    public function has(string $table): bool
    {
        return isset($this->manifest['tables'][$table]);
    }

    public function count(string $table): int
    {
        return (int) ($this->manifest['tables'][$table]['rows'] ?? 0);
    }

    /** @return list<string> */
    public function primaryKey(string $table): array
    {
        return $this->manifest['tables'][$table]['primary_key'] ?? [];
    }

    /** @return Generator<int, array<string, mixed>> */
    public function rows(string $table): Generator
    {
        if (! $this->has($table)) {
            return;
        }
        $handle = fopen($this->dir.'/'.$table.'.jsonl', 'r');
        if ($handle === false) {
            return;
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        fclose($handle);
    }

    /** Drops cached tables (between stages, to keep a long import's memory flat). */
    public function forget(): void
    {
        $this->cache = [];
    }

    /** Whole (small) table, cached. @return list<array<string, mixed>> */
    public function all(string $table): array
    {
        return $this->cache[$table] ??= iterator_to_array($this->rows($table), false);
    }
}
