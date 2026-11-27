<?php

namespace App\Domain\Legacy\Import;

use App\Domain\CashManagement\Services\BranchDrawer;
use App\Domain\CashManagement\Services\ShiftLink;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Legacy\Import\Stages\AccountsStage;
use App\Domain\Legacy\Import\Stages\ArchiveStage;
use App\Domain\Legacy\Import\Stages\CashSessionsStage;
use App\Domain\Legacy\Import\Stages\CatalogStage;
use App\Domain\Legacy\Import\Stages\ContactsStage;
use App\Domain\Legacy\Import\Stages\DocumentsStage;
use App\Domain\Legacy\Import\Stages\FoundationStage;
use App\Domain\Legacy\Import\Stages\JournalsStage;
use App\Domain\Legacy\Import\Stages\OpeningEntryStage;
use App\Domain\Legacy\Import\Stages\ReconciliationStage;
use App\Domain\Legacy\Import\Stages\UsersStage;
use App\Domain\Legacy\Import\Stages\VouchersStage;
use App\Domain\Legacy\Models\LegacyImport;
use Spatie\Activitylog\Support\ActivityLogger;
use Throwable;

/**
 * Runs the import stages in order. Every stage is idempotent (old → new ids are remembered in legacy_id_map),
 * so a later, fresher backup can be imported over the first one: existing records are updated, new ones added.
 */
final class LegacyImporter
{
    /** @return list<class-string<Stage>> */
    public static function stages(): array
    {
        return [
            ArchiveStage::class,
            AccountsStage::class,
            FoundationStage::class,
            UsersStage::class,
            ContactsStage::class,
            CatalogStage::class,
            OpeningEntryStage::class,
            CashSessionsStage::class,
            DocumentsStage::class,
            VouchersStage::class,
            JournalsStage::class,
            ReconciliationStage::class,
        ];
    }

    /** Every contact's cached balance recomputed from its documents — the import touched them in bulk. */
    private function refreshBalances(): void
    {
        $ledger = app(ContactLedger::class);
        Contact::query()->each(fn (Contact $contact) => $ledger->refresh($contact));
    }

    /** @param list<string>|null $only stage names to run (default: all) */
    public function run(LegacyImport $import, ?array $only = null, ?\Closure $onStage = null): LegacyImport
    {
        // hundreds of thousands of copied records: one "import" entry in the activity log, not one per record
        activity('legacy_import')->event('import')->withProperty('package', basename((string) $import->package_path))->log('import');

        // old payments belong to no shift of the new system, even when dated on a day a shift is open
        return ShiftLink::without(fn () => app(ActivityLogger::class)->withoutLogging(fn () => $this->runStages($import, $only, $onStage)));
    }

    /** @param list<string>|null $only */
    private function runStages(LegacyImport $import, ?array $only, ?\Closure $onStage): LegacyImport
    {
        $package = new LegacyPackage((string) $import->package_path);
        $context = new ImportContext($import);
        $import->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error' => null,
            'manifest' => $import->manifest ?? $package->manifest()]);

        try {
            foreach (self::stages() as $class) {
                $stage = app($class);
                if ($only !== null && ! in_array($stage->name(), $only, true)) {
                    continue;
                }
                $onStage && $onStage($stage->name());
                $context->progress($stage->name(), 0, 1, force: true);
                if ($stage instanceof ReconciliationStage) {
                    $this->refreshBalances(); // compare against fresh balances
                }
                $stage->run($package, $context);
                $package->forget();
                gc_collect_cycles();
                $context->progress($stage->name(), 1, 1, force: true);
            }
            if ($only === null) {
                app(BranchDrawer::class)->consolidate(); // the old system's drawers: the branch is the drawer here
            }
            $import->update(['status' => 'completed', 'stage' => null, 'finished_at' => now(), 'report' => $context->report()]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'finished_at' => now(), 'report' => $context->report(),
                'error' => $e->getMessage()."\n".$e->getFile().':'.$e->getLine()]);
            throw $e;
        }

        return $import;
    }
}
