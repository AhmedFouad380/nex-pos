<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Data\JournalDraft;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Services\Posting;
use App\Domain\Finance\Services\SystemAccounts;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manual journal vouchers (Source 1) — mostly salaries due to employees — and shift closings (Source 29), which
 * move a cashier's custody into the branch box. A line on a customer / supplier /
 * employee account goes to that control account, tagged with the contact, so it shows in their statement.
 * Year-closing vouchers (Source 6) were computed on the old ledger's figures and would leave differences in
 * the profit & loss accounts; they stay in the archive and are counted in the report.
 */
final class JournalsStage implements Stage
{
    public function name(): string
    {
        return 'journals';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $headers = [];
        foreach ($package->rows('JournalVoucher') as $jv) {
            $source = (int) ($jv['Source'] ?? 0);
            if ($source === 6) {
                $context->count($this->name(), 'year closings kept in archive');
            } elseif (in_array($source, [1, 29], true)) { // manual entries, and shift closings (custody → box)
                $headers[ImportContext::key($jv['BranchId'], $jv['JVDId'], $jv['FYId'], $jv['JVId'])] = $jv;
            }
        }
        $lines = [];
        foreach ($package->rows('JournalVoucherDetails') as $d) {
            $key = ImportContext::key($d['BranchId'], $d['JVDId'], $d['FYId'], $d['JVId']);
            if (isset($headers[$key])) {
                $lines[$key][] = $d;
            }
        }
        uasort($headers, fn ($a, $b) => $a['JVDate'] <=> $b['JVDate']);

        ContactLedger::deferring(fn () => DB::transaction(function () use ($headers, $lines, $context) {
            foreach ($headers as $key => $jv) {
                if ($context->newId('JournalVoucher', $key)) {
                    continue;
                }
                $draft = new JournalDraft(Carbon::parse($jv['JVDate']),
                    trim((string) ($jv['Description'] ?? '')) ?: __('legacy.manual_entry', ['number' => $jv['JVSerial']]),
                    $context->newId('Branch', (string) $jv['BranchId']));
                foreach ($lines[$key] ?? [] as $line) {
                    [$account, $contact] = $this->target((string) $line['AccountId'], $context);
                    if (! $account) {
                        $context->warn(__('legacy.warnings.document_skipped', ['doc' => 'JV '.$jv['JVSerial'], 'reason' => 'account '.$line['AccountId']]));

                        continue;
                    }
                    $net = (string) ((float) ($line['DebitLocal'] ?? $line['Debit'] ?? 0) - (float) ($line['CreditLocal'] ?? $line['Credit'] ?? 0));
                    $draft->debit($account, $net, $contact, trim((string) ($line['Description'] ?? '')) ?: null);
                }
                if ($draft->lines() === [] || ! $draft->imbalance()->isZero()) {
                    $context->warn(__('legacy.warnings.document_skipped', ['doc' => 'JV '.$jv['JVSerial'], 'reason' => 'unbalanced']));

                    continue;
                }
                $entry = app(Posting::class)->manual($draft);
                foreach (collect($draft->lines())->pluck('contact_id')->filter()->unique() as $contactId) {
                    app(ContactLedger::class)->refresh(Contact::query()->findOrFail($contactId));
                }
                $context->remember('JournalVoucher', $key, $entry);
                $context->count($this->name(), 'manual entries');
            }
        }));
    }

    /** @return array{0: ?int, 1: ?int} [account id, contact id] */
    private function target(string $code, ImportContext $context): array
    {
        if ($custody = $context->newId('Custody', $code)) {
            return [$custody, null];
        }
        if ($context->newType('ChartOfAccount', $code) === 'contact') {
            $contact = Contact::query()->findOrFail($context->newId('ChartOfAccount', $code));
            $role = match ($contact->type) {
                ContactType::Employee => Role::EmployeeAccounts,
                ContactType::Supplier => Role::Payable,
                default => Role::Receivable,
            };

            return [app(SystemAccounts::class)->id($role), $contact->id];
        }
        $id = $context->newId('ChartOfAccount', $code);

        return [$id && Account::query()->whereKey($id)->where('is_postable', true)->exists() ? $id : null, null];
    }
}
