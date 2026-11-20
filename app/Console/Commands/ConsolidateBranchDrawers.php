<?php

namespace App\Console\Commands;

use App\Domain\CashManagement\Services\BranchDrawer;
use Illuminate\Console\Command;

/** One cash drawer per branch: keeps the drawer in use, switches off the others with history, deletes unused ones. */
class ConsolidateBranchDrawers extends Command
{
    protected $signature = 'cash:branch-drawers';

    protected $description = 'Leave one cash drawer per branch (the branch is the drawer)';

    public function handle(BranchDrawer $drawers): int
    {
        $result = $drawers->consolidate();
        $this->info("Kept {$result['kept']}, switched off {$result['deactivated']}, deleted {$result['deleted']} unused, created {$result['created']}.");

        return self::SUCCESS;
    }
}
