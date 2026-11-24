<?php

namespace App\Console\Commands;

use App\Domain\Payroll\Actions\OpenAdvancesFromBalances;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Turns what each employee owes (their account balance from the old system) into advances, one per payment. */
#[Signature('payroll:advances-from-balances {--except=* : Contact codes to skip (accounts that are not people)} {--dry-run : Only list what would be created}')]
#[Description('Open advances for employees from their account balances (old-system data)')]
class OpenLegacyAdvances extends Command
{
    public function handle(OpenAdvancesFromBalances $action): int
    {
        $rows = $action->handle($this->option('except'), (bool) $this->option('dry-run'));
        $this->table(['Employee', 'Code', 'Balance', 'Advances', 'New profile'],
            array_map(fn ($r) => [$r['employee'], $r['code'], $r['balance'], $r['advances'], $r['profile'] ?? '-'], $rows));
        $this->info(sprintf('%d employees, %d advances%s.', count($rows), array_sum(array_column($rows, 'advances')), $this->option('dry-run') ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}
