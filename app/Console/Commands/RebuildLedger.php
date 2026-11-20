<?php

namespace App\Console\Commands;

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\CRM\Models\LedgerDiscount;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\Posting;
use App\Domain\Finance\Services\PostingRules;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Re-posts the whole general ledger from the documents, in date order. Manual entries are kept.
 * Use after importing legacy data, or after changing a posting rule.
 */
#[Signature('ledger:rebuild {--force : Do not ask for confirmation}')]
#[Description('Delete automatic journal entries and post them again from the documents')]
class RebuildLedger extends Command
{
    /** class => date column used to replay documents in order */
    private const DATES = [
        ContactOpeningBalance::class => 'as_of_date',
        StockAdjustment::class => 'adjusted_at',
        Purchase::class => 'purchase_date',
        PurchaseReturn::class => 'return_date',
        Sale::class => 'sale_date',
        SaleReturn::class => 'return_date',
        Expense::class => 'expense_date',
        Payment::class => 'paid_at',
        LedgerDiscount::class => 'date',
        AccountTransfer::class => 'transferred_at',
        CashSession::class => 'closed_at',
        PayrollRun::class => 'approved_at',
    ];

    public function handle(Posting $posting): int
    {
        if (! $this->option('force') && ! $this->confirm(__('All automatic journal entries will be deleted and posted again. Continue?'), true)) {
            return self::FAILURE;
        }

        $queue = collect(PostingRules::DOCUMENTS)->flatMap(function (string $class) {
            /** @var class-string<Model> $class */
            $column = self::DATES[$class];

            return $class::query()->whereNotNull($column)->toBase()->get(['id', $column])
                ->map(fn ($row) => [$class, $row->id, (string) $row->{$column}]);
        })->sortBy(fn (array $item) => $item[2].'|'.array_search($item[0], PostingRules::DOCUMENTS, true))->values();

        DB::transaction(function () use ($queue, $posting) {
            JournalEntry::query()->where('is_manual', false)->whereNotNull('reversed_entry_id')->delete(); // reversals first (FK)
            JournalEntry::query()->where('is_manual', false)->delete();

            $bar = $this->output->createProgressBar($queue->count());
            foreach ($queue as [$class, $id]) {
                $posting->sync($class::query()->findOrFail($id));
                $bar->advance();
            }
            $bar->finish();
        });

        $this->newLine();
        $totals = DB::table('journal_lines')->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first();
        $this->info(__('Posted :count documents. Total debit :debit, total credit :credit.', [
            'count' => $queue->count(), 'debit' => number_format((float) $totals->d, 2), 'credit' => number_format((float) $totals->c, 2),
        ]));

        return self::SUCCESS;
    }
}
