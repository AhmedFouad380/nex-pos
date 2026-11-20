<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\CRM\Actions\RecordOpeningBalance;
use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Customers (51), suppliers (50) and employees (52) of SupCusEmp, plus every other account the old chart kept
 * under the customers / suppliers / employees parents (e.g. "الرصيد") — each becomes a contact, so every voucher
 * has someone to post to. The old cash customer becomes the walk-in customer.
 * Opening balances come from AccountsOpeningBalance (the old opening entry), dated the first financial year.
 */
final class ContactsStage implements Stage
{
    private const TYPES = [50 => ContactType::Supplier, 51 => ContactType::Customer, 52 => ContactType::Employee];

    private const PREFIX = ['supplier' => 'S', 'customer' => 'C', 'employee' => 'E'];

    public function name(): string
    {
        return 'contacts';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        DB::transaction(function () use ($package, $context) {
            foreach ($package->all('SupCusEmp') as $row) {
                $type = self::TYPES[(int) $row['FormType']] ?? null;
                if (! $type) {
                    continue;
                }
                $contact = Contact::query()->find($context->newId('SupCusEmp', (string) $row['Id']));
                if (! $contact && (bool) ($row['POSDefaultCusCash'] ?? false)) {
                    $contact = Contact::query()->where('is_default', true)->first(); // the old "cash customer"
                }
                $contact ??= new Contact(['type' => $type]);
                $credit = Decimal::of($row['CreditLimit'] ?? 0);
                $contact->fill([
                    'code' => $contact->code ?? self::PREFIX[$type->value].'-'.trim((string) ($row['Code'] ?: $row['Id'])),
                    'name' => $contact->is_default ? $contact->name : (trim((string) ($row['NameA'] ?: $row['NameE'])) ?: '#'.$row['Id']),
                    'mobile' => $this->phone($row['Mobile'] ?? null) ?? $this->phone($row['Tel1'] ?? null),
                    'alt_mobile' => $this->phone($row['Tel2'] ?? null),
                    'landline' => $this->phone($row['Tel1'] ?? null),
                    'email' => filter_var(trim((string) ($row['EMail'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
                    'address' => trim((string) ($row['Address'] ?? '')) ?: null,
                    'tax_number' => trim((string) ($row['TaxRegistrationNo'] ?? '')) ?: null,
                    'credit_limit' => (bool) ($row['AllowCreditLimit'] ?? false) && $credit->isPositive() ? Decimal::toDb($credit) : null,
                    'note' => trim((string) ($row['Notes'] ?? '')) ?: null,
                    'is_active' => true,
                ])->save();
                $context->remember('SupCusEmp', (string) $row['Id'], $contact);
                if (filled($row['AccountId'] ?? null)) {
                    $context->remember('ChartOfAccount', (string) $row['AccountId'], $contact);
                }
                $context->count($this->name(), $type->value);
            }

            $this->accountsWithoutContact($package, $context);
            $this->openingBalances($package, $context);
        });
    }

    /** Accounts under the customers / suppliers / employees parents that SupCusEmp doesn't know. */
    private function accountsWithoutContact(LegacyPackage $package, ImportContext $context): void
    {
        foreach ($package->all('ChartOfAccount') as $row) {
            $parent = (string) ($row['AccountIdParent'] ?? '');
            $id = (string) $row['AccountId'];
            if (! isset(AccountsStage::CONTACT_PARENTS[$parent]) || $context->newType('ChartOfAccount', $id) === 'contact') {
                continue;
            }
            $type = ContactType::from(AccountsStage::CONTACT_PARENTS[$parent][1]);
            $contact = Contact::query()->firstOrNew(['code' => self::PREFIX[$type->value].'-A'.$id], ['type' => $type]);
            $contact->fill(['name' => trim((string) ($row['NameA'] ?: $row['NameE'])) ?: $id, 'is_active' => true])->save();
            $context->remember('ChartOfAccount', $id, $contact);
            $context->count($this->name(), 'from accounts');
        }
    }

    private function openingBalances(LegacyPackage $package, ImportContext $context): void
    {
        $asOf = $this->firstDay($package);
        foreach ($package->all('AccountsOpeningBalance') as $row) {
            $key = ImportContext::key($row['BranchId'], $row['Serial']);
            if ($context->newType('ChartOfAccount', (string) $row['AccountId']) !== 'contact' || $context->newId('AccountsOpeningBalance', $key)) {
                continue;
            }
            $net = Decimal::of($row['DebitLocal'] ?? $row['Debit'] ?? 0)->minus(Decimal::of($row['CreditLocal'] ?? $row['Credit'] ?? 0));
            if ($net->isZero()) {
                continue;
            }
            $contact = Contact::query()->findOrFail($context->newId('ChartOfAccount', (string) $row['AccountId']));
            $balance = app(RecordOpeningBalance::class)->handle($contact, Decimal::toDb($net->abs()),
                $net->isPositive() ? BalanceDirection::Receivable : BalanceDirection::Payable, $asOf,
                $context->newId('Branch', (string) $row['BranchId']), trim((string) ($row['Description'] ?? '')) ?: null);
            $context->remember('AccountsOpeningBalance', $key, $balance);
            $context->count($this->name(), 'opening balances');
        }
    }

    public function firstDay(LegacyPackage $package): string
    {
        $dates = array_filter(array_map(fn ($y) => substr((string) $y['BeginDate'], 0, 10), $package->all('FinancialYear')));

        return $dates ? min($dates) : now()->startOfYear()->toDateString();
    }

    private function phone(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : mb_substr($value, 0, 30);
    }
}
