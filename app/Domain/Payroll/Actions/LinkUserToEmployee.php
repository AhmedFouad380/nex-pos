<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ties a login to the employee it belongs to (one each way) — needed to ask for an advance and to keep someone from
 * deciding on their own. Employees without salary data get an empty profile in the user's branch.
 */
final class LinkUserToEmployee
{
    public function handle(User $user, ?Contact $employee): void
    {
        if ($employee !== null && $employee->type !== ContactType::Employee) {
            throw new DomainException(__('payroll.errors.not_employee'));
        }

        DB::transaction(function () use ($user, $employee) {
            EmployeeProfile::query()->where('user_id', $user->id)
                ->when($employee !== null, fn ($q) => $q->where('contact_id', '!=', $employee->id))->update(['user_id' => null]);
            if ($employee === null) {
                return;
            }
            $profile = EmployeeProfile::query()->firstOrNew(['contact_id' => $employee->id]);
            if (! $profile->exists) {
                $profile->fill(['location_id' => $user->locations()->value('locations.id'), 'status' => EmployeeStatus::Active, 'base_salary' => 0]);
            }
            $profile->user_id = $user->id;
            $profile->save();
        });
    }
}
