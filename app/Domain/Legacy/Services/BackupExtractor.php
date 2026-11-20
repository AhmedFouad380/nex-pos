<?php

namespace App\Domain\Legacy\Services;

use App\Support\DomainException;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Opens a SQL Server backup (.bck / .bak) of the old system in a throw-away SQL Server container and
 * writes EVERY non-empty table to a package: `<table>.jsonl` (one row per line) + `manifest.json`.
 *
 * Values are exported as text where precision matters: decimals / money as strings (no float rounding),
 * dates as ISO strings, binary columns (the logo) as base64. Needs Docker on the machine that runs it.
 */
final class BackupExtractor
{
    private const IMAGE = 'mcr.microsoft.com/mssql/server:2022-latest';

    private const SQLCMD = '/opt/mssql-tools18/bin/sqlcmd';

    private const DATABASE = 'legacy';

    private const CHUNK = 5000;

    /** @var (Closure(string): void)|null */
    private ?Closure $log = null;

    public function __construct(
        private readonly string $container = 'newpos-legacy-mssql',
        private readonly string $password = 'Legacy#Import2026',
    ) {}

    /** @param Closure(string): void $log */
    public function onProgress(Closure $log): self
    {
        $this->log = $log;

        return $this;
    }

    /** @return array<string, mixed> the manifest */
    public function extract(string $backupPath, string $outDir): array
    {
        $backupPath = realpath($backupPath) ?: throw new DomainException(__('legacy.errors.file_not_found', ['file' => $backupPath]));
        $this->startServer(dirname($backupPath));
        $header = $this->restore('/backup/'.basename($backupPath));

        File::ensureDirectoryExists($outDir);
        $tables = $this->tables();
        $manifest = [
            'source' => basename($backupPath),
            'database' => $header['DatabaseName'] ?? null,
            'backup_date' => $header['BackupFinishDate'] ?? null,
            'extracted_at' => now()->toIso8601String(),
            'tables' => [],
        ];

        foreach ($tables as $name => $table) {
            $this->say("{$name} ({$table['rows']})");
            $written = $this->exportTable($name, $table, $outDir.'/'.$name.'.jsonl');
            $manifest['tables'][$name] = ['rows' => $written, 'columns' => array_keys($table['columns']), 'primary_key' => $table['primary_key']];
        }

        File::put($outDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    public function stopServer(): void
    {
        Process::run(['docker', 'rm', '-f', $this->container]);
    }

    private function startServer(string $backupDir): void
    {
        if (! Process::run(['docker', 'info'])->successful()) {
            throw new DomainException(__('legacy.errors.docker_missing'));
        }

        $mounted = trim(Process::run(['docker', 'inspect', '-f', '{{range .Mounts}}{{.Source}}{{end}}', $this->container])->output());
        if ($mounted !== '' && $mounted !== $backupDir) {
            $this->stopServer(); // started for another folder
            $mounted = '';
        }
        if ($mounted === '') {
            $this->say(__('Starting SQL Server…'));
            Process::timeout(900)->run(['docker', 'run', '-d', '--name', $this->container, '--platform', 'linux/amd64',
                '-e', 'ACCEPT_EULA=Y', '-e', 'MSSQL_SA_PASSWORD='.$this->password, '-e', 'MSSQL_PID=Developer',
                '-v', $backupDir.':/backup:ro', self::IMAGE])->throw();
        } else {
            Process::run(['docker', 'start', $this->container]);
        }

        for ($i = 0; $i < 60; $i++) {
            if (Process::run([...$this->sqlcmd('master'), '-Q', 'SELECT 1'])->successful()) {
                return;
            }
            sleep(3);
        }
        throw new DomainException(__('legacy.errors.server_not_ready'));
    }

    /** @return array<string, string> backup header (DatabaseName, BackupFinishDate…) */
    private function restore(string $file): array
    {
        $header = $this->rows("RESTORE HEADERONLY FROM DISK = N'{$file}'", 'master')[0] ?? [];
        $files = $this->rows("RESTORE FILELISTONLY FROM DISK = N'{$file}'", 'master');
        $moves = [];
        foreach ($files as $f) {
            $ext = ($f['Type'] ?? 'D') === 'L' ? 'ldf' : 'mdf';
            $moves[] = "MOVE N'{$f['LogicalName']}' TO N'/var/opt/mssql/data/".self::DATABASE.'_'.count($moves).".{$ext}'";
        }

        $this->say(__('Restoring the backup…'));
        $this->run('IF DB_ID(\''.self::DATABASE.'\') IS NOT NULL BEGIN ALTER DATABASE ['.self::DATABASE.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE ['.self::DATABASE.'] END', 'master');
        $this->run('RESTORE DATABASE ['.self::DATABASE."] FROM DISK = N'{$file}' WITH ".implode(', ', $moves), 'master', 1800);
        $this->run('ALTER DATABASE ['.self::DATABASE.'] SET COMPATIBILITY_LEVEL = 160', 'master'); // old backups restore at an old level

        return $header;
    }

    /**
     * Non-empty tables with their columns (name => SQL type) and primary key.
     *
     * @return array<string, array{rows: int, columns: array<string, string>, primary_key: list<string>}>
     */
    private function tables(): array
    {
        $tables = [];
        foreach ($this->rows('SELECT t.name AS t, SUM(p.rows) AS r FROM sys.tables t JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
            GROUP BY t.name HAVING SUM(p.rows) > 0 ORDER BY t.name', self::DATABASE) as $row) {
            $tables[$row['t']] = ['rows' => (int) $row['r'], 'columns' => [], 'primary_key' => []];
        }
        foreach ($this->rows('SELECT t.name AS t, c.name AS c, ty.name AS ty FROM sys.tables t JOIN sys.columns c ON c.object_id = t.object_id
            JOIN sys.types ty ON ty.user_type_id = c.user_type_id ORDER BY t.name, c.column_id', self::DATABASE) as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['columns'][$row['c']] = $row['ty'];
            }
        }
        foreach ($this->rows('SELECT t.name AS t, c.name AS c FROM sys.tables t JOIN sys.indexes i ON i.object_id = t.object_id AND i.is_primary_key = 1
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id ORDER BY t.name, ic.key_ordinal', self::DATABASE) as $row) {
            if (isset($tables[$row['t']])) {
                $tables[$row['t']]['primary_key'][] = $row['c'];
            }
        }

        return $tables;
    }

    /** @param array{rows: int, columns: array<string, string>, primary_key: list<string>} $table */
    private function exportTable(string $name, array $table, string $path): int
    {
        $select = [];
        $orderable = [];
        foreach ($table['columns'] as $column => $type) {
            $q = '['.$column.']';
            $select[] = match (true) {
                in_array($type, ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'], true) => "CONVERT(nvarchar(60), {$q}) AS {$q}",
                in_array($type, ['datetime', 'datetime2', 'smalldatetime', 'date', 'datetimeoffset'], true) => "CONVERT(varchar(33), {$q}, 126) AS {$q}",
                in_array($type, ['time'], true) => "CONVERT(varchar(16), {$q}) AS {$q}",
                in_array($type, ['uniqueidentifier', 'sql_variant', 'xml', 'hierarchyid'], true) => "CONVERT(nvarchar(max), {$q}) AS {$q}",
                in_array($type, ['timestamp', 'rowversion'], true) => "CONVERT(varchar(20), CONVERT(bigint, {$q})) AS {$q}",
                default => $q, // text and numbers as they are; binary comes out as base64
            };
            if (! in_array($type, ['text', 'ntext', 'image', 'xml', 'varbinary', 'binary', 'sql_variant', 'geography', 'geometry'], true)) {
                $orderable[] = $q;
            }
        }
        // a stable order is needed to page through the table without skipping or repeating rows
        $order = $table['primary_key'] !== [] ? implode(', ', array_map(fn ($c) => '['.$c.']', $table['primary_key']))
            : ($orderable !== [] ? implode(', ', $orderable) : '(SELECT NULL)');

        $handle = fopen($path, 'w') ?: throw new DomainException(__('legacy.errors.cannot_write', ['file' => $path]));
        $written = 0;
        for ($offset = 0; $offset < max($table['rows'], 1); $offset += self::CHUNK) {
            $sql = 'SET NOCOUNT ON; DECLARE @j nvarchar(max) = (SELECT '.implode(', ', $select)." FROM [{$name}] ORDER BY {$order}
                OFFSET {$offset} ROWS FETCH NEXT ".self::CHUNK.' ROWS ONLY FOR JSON PATH, INCLUDE_NULL_VALUES); SELECT @j';
            $json = trim($this->run($sql, self::DATABASE, 600, json: true));
            if ($json === '' || $json === 'NULL') {
                break;
            }
            $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            foreach ($rows as $row) {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $written++;
            }
        }
        fclose($handle);

        return $written;
    }

    /** @return list<string> */
    private function sqlcmd(string $database): array
    {
        return ['docker', 'exec', $this->container, self::SQLCMD, '-S', 'localhost', '-U', 'sa', '-P', $this->password, '-C', '-d', $database, '-b'];
    }

    private function run(string $sql, string $database, int $timeout = 120, bool $json = false): string
    {
        $args = [...$this->sqlcmd($database), ...($json ? ['-y', '0'] : ['-W']), '-Q', $sql];
        $result = Process::timeout($timeout)->run($args);
        if (! $result->successful()) {
            throw new DomainException(__('legacy.errors.sql_failed', ['message' => trim($result->output()."\n".$result->errorOutput())]));
        }

        return $result->output();
    }

    /** @return list<array<string, string>> rows of a small result set, keyed by column name */
    private function rows(string $sql, string $database): array
    {
        $sep = "\x1f";
        $result = Process::timeout(300)->run([...$this->sqlcmd($database), '-W', '-s', $sep, '-Q', 'SET NOCOUNT ON; '.$sql]);
        if (! $result->successful()) {
            throw new DomainException(__('legacy.errors.sql_failed', ['message' => trim($result->output()."\n".$result->errorOutput())]));
        }
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $result->output())), fn ($l) => $l !== ''));
        if ($lines === []) {
            return [];
        }
        $columns = explode($sep, array_shift($lines));
        array_shift($lines); // the ----- line under the header

        return array_map(fn ($line) => array_combine($columns, array_pad(explode($sep, $line), count($columns), '')), $lines);
    }

    private function say(string $message): void
    {
        if ($this->log) {
            ($this->log)($message);
        }
    }
}
