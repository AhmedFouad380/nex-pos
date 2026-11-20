<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\WalletNotifier;
use Illuminate\Database\Eloquent\Builder;

/** After a template was added or fixed: reads unparsed (and still pending) messages again. */
final class ReadWalletMessageAgain
{
    public function __construct(
        private readonly IngestWalletMessage $ingest,
        private readonly WalletNotifier $notifier,
    ) {}

    /**
     * @param  ?Builder<WalletTransaction>  $query  limit to these (default: all)
     * @return int how many became readable
     */
    public function handle(?Builder $query = null): int
    {
        $read = 0;
        foreach (($query ?? WalletTransaction::query())->whereIn('status', [Status::Unparsed, Status::Pending])->get() as $transaction) {
            $wasUnparsed = $transaction->status === Status::Unparsed;
            $this->ingest->read($transaction);
            $transaction->save();
            if ($wasUnparsed && $transaction->status === Status::Pending) {
                $read++;
                $this->notifier->arrived($transaction);
            }
        }

        return $read;
    }
}
