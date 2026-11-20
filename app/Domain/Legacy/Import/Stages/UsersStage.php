<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Old users become users of the new system. Old passwords cannot be carried over, so they come in with a
 * random password and login switched off — an admin sets each password (and turns login on) from the users screen.
 * Role: chosen on the import page (options.users.<id>.role), else manager for old type 1, cashier otherwise.
 * Branches: from UserStores.
 */
final class UsersStage implements Stage
{
    public function name(): string
    {
        return 'users';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $stores = collect($package->all('UserStores'))->filter(fn ($s) => (bool) $s['Checked'])->groupBy(fn ($s) => (string) $s['UserId']);

        DB::transaction(function () use ($package, $context, $stores) {
            foreach ($package->all('Users') as $old) {
                $id = (string) $old['UserId'];
                $user = User::query()->find($context->newId('Users', $id));
                if (! $user) {
                    $user = new User([
                        'password' => Str::password(32),
                        'is_active' => true,
                        'allow_login' => false,
                    ]);
                }
                $user->fill([
                    'name' => trim((string) $old['UserName']) ?: 'User '.$id,
                    'username' => $user->username ?? $this->username((string) $old['UserName'], $id),
                    'email' => $user->email ?? 'legacy-user-'.$id.'@import.invalid',
                ])->save();

                $role = $context->option("users.{$id}.role") ?: ((int) $old['UserType'] === 1 ? 'manager' : 'cashier');
                $user->syncRoles([$role]);
                $locations = $stores->get($id, collect())->map(fn ($s) => $context->newId('Store', (string) $s['StoreId']))->filter()->unique()->values()->all();
                $user->locations()->sync($locations);

                $context->remember('Users', $id, $user);
                $context->count($this->name(), 'users');
            }
        });
    }

    private function username(string $name, string $id): string
    {
        $base = Str::slug($name) ?: 'user';
        $candidate = $base.'-'.$id;

        return User::query()->where('username', $candidate)->exists() ? $candidate.'-'.Str::lower(Str::random(4)) : $candidate;
    }
}
