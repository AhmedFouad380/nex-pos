<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * دليل الاستخدام: screenshots of every screen (scripts/guide/shots.mjs) on a separate demo database,
 * then the PDF of all the help files with them (scripts/guide/pdf.mjs → public/guide/user-guide.pdf).
 * Needs Node and Google Chrome on the machine that runs it; the client's server only receives the result.
 */
class BuildUserGuide extends Command
{
    protected $signature = 'guide:build
        {--fresh : Recreate the guide database with GuideSeeder first}
        {--only=* : Retake only these screenshots (names from scripts/guide/shots.mjs)}
        {--no-shots : Keep the current screenshots and only print the PDF}
        {--port=8123 : Port of the temporary server}';

    protected $description = 'Take the screenshots of the user guide and print it to public/guide/user-guide.pdf';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('guide:build runs on a development machine only.');

            return self::FAILURE;
        }
        $database = (string) config('newpos.guide_database');
        if ($database === '' || $database === config('database.connections.'.config('database.default').'.database')) {
            $this->error('The guide database must be a separate database (config newpos.guide_database).');

            return self::FAILURE;
        }
        $env = ['DB_DATABASE' => $database];

        if ($this->option('fresh')) {
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->info("Filling {$database} with demo data…");
            $seed = Process::env($env)->timeout(600)->run([PHP_BINARY, 'artisan', 'migrate:fresh', '--seed', '--seeder=GuideSeeder', '--force']);
            if ($seed->failed()) {
                $this->error($seed->errorOutput() ?: $seed->output());

                return self::FAILURE;
            }
        }

        $port = (int) $this->option('port');
        $url = "http://127.0.0.1:{$port}";
        $server = new SymfonyProcess([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            public_path(), $env);
        $server->disableOutput(); // its request log would fill the pipe and freeze the server
        $server->start();

        try {
            if (! $this->waitFor($url.'/admin/login')) {
                $this->error("The temporary server did not start on {$url} (is the port free?).");

                return self::FAILURE;
            }
            $node = fn (array $args) => Process::env(['GUIDE_URL' => $url])->timeout(1200)->path(base_path())
                ->run(['node', ...$args], fn (string $type, string $output) => $this->output->write($output));

            if (! $this->option('no-shots') && $node(['scripts/guide/shots.mjs', ...$this->option('only')])->failed()) {
                $this->error('Some screenshots failed (see above).');

                return self::FAILURE;
            }
            if ($node(['scripts/guide/pdf.mjs', 'public/guide/user-guide.pdf'])->failed()) {
                $this->error('Printing the PDF failed (see above).');

                return self::FAILURE;
            }
        } finally {
            $server->stop();
        }

        $this->info('User guide: '.public_path('guide/user-guide.pdf'));

        return self::SUCCESS;
    }

    private function waitFor(string $url): bool
    {
        for ($i = 0; $i < 40; $i++) {
            if (rescue(fn () => Http::timeout(2)->get($url)->successful(), false, false)) {
                return true;
            }
            usleep(250_000);
        }

        return false;
    }
}
