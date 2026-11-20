<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Import\LegacyImporter;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Models\LegacyImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Imports a package written by legacy:extract (a folder or a .zip). Safe to run again with a newer package. */
#[Signature('legacy:import {package : Folder or .zip from legacy:extract} {--only=* : Run only these stages}')]
#[Description('Import the old system data package into this system')]
class ImportLegacyPackage extends Command
{
    public function handle(LegacyImporter $importer): int
    {
        $path = (string) $this->argument('package');
        if (str_ends_with($path, '.zip')) {
            $path = LegacyPackage::fromZip($path, storage_path('app/legacy/'.pathinfo($path, PATHINFO_FILENAME)))->dir;
        }
        $package = new LegacyPackage(realpath($path) ?: $path);

        $import = LegacyImport::query()->create([
            'source_name' => $package->manifest()['source'] ?? basename($path),
            'package_path' => $package->dir,
            'manifest' => $package->manifest(),
        ]);

        $started = microtime(true);
        $importer->run($import, $this->option('only') ?: null, fn (string $stage) => $this->line(sprintf('» %s (%dMB)', $stage, memory_get_usage(true) / 1048576)));

        foreach ($import->refresh()->report['counts'] ?? [] as $stage => $counts) {
            $this->line(sprintf('  %-16s %s', $stage, collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ')));
        }
        foreach (array_slice($import->report['warnings'] ?? [], 0, 20) as $warning) {
            $this->warn('  ! '.$warning);
        }
        $this->info(sprintf('Done in %ds.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
