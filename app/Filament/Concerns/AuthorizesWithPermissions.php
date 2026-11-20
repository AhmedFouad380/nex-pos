<?php

namespace App\Filament\Concerns;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Maps Filament's resource abilities onto config/permissions.php names, so no policy class is
 * needed per model. Either set
 *   protected static string $permissionPrefix = 'products';  // products.view / .create / .update / .delete
 * or override static::permissionFor() (one permission for everything, or immutable documents).
 */
trait AuthorizesWithPermissions
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;
        $permission = static::permissionFor($action);

        if ($permission === false) {
            return Response::deny();
        }

        return auth()->user()?->can($permission) ? Response::allow() : Response::deny();
    }

    /** @return string|false the permission to check, or false when the ability is never allowed */
    protected static function permissionFor(string $action): string|false
    {
        $ability = match ($action) {
            'viewAny', 'view' => 'view',
            'create', 'replicate' => 'create',
            'update', 'reorder' => 'update',
            default => 'delete', // delete, deleteAny, forceDelete(Any), restore(Any)
        };

        return static::$permissionPrefix.'.'.$ability;
    }
}
