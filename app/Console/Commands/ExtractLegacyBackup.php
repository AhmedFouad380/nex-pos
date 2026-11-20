<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\BackupExtractor;
use App\Support\DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Turns the old system's SQL Server backup into an import package (every non-empty table as JSON lines).
 * Run it on any machine with Docker; upload the resulting .zip on the "import from the old system" page.
 */
#[Signature('legacy:extract {backup : Path to the .bck / .bak file} {--out= : Output folder (default storage/app/legacy/<name>)} {--zip : Also write a .zip next to the folder} {--stop : Remove the SQL Server container afterwards}')]
#[Description('Extract every table of the old system backup into an import package')]
class ExtractLegacyBackup extends Command
{
    public function handle(): int
    {
        $backup = (string) $this->argument('backup');
        $out = $this->option('out') ?: storage_path('app/legacy/'.pathinfo($backup, PATHINFO_FILENAME));
        $extractor = (new BackupExtractor)->onProgress(fn (string $m) => $this->line('  '.$m));

        try {
            $started = microtime(true);
            $manifest = $extractor->extract($backup, $out);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($this->option('stop')) {
                $extractor->stopServer();
            }
        }

        $rows = array_sum(array_column($manifest['tables'], 'rows'));
        $this->info(sprintf('%d tables, %s rows → %s (%ds)', count($manifest['tables']), number_format($rows), $out, microtime(true) - $started));

        if ($this->option('zip')) {
            $zipPath = rtrim($out, '/').'.zip';
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach (glob($out.'/*') ?: [] as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
            $this->info('Package: '.$zipPath);
        }

        return self::SUCCESS;
    }
}
