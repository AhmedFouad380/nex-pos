<?php

namespace App\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Support\Audit\Audited;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'pin', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const SUPER_ADMIN_ROLE = 'super-admin';

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'language' => 'ar',
        'is_active' => true,
        'allow_login' => true,
        'is_service_staff' => false,
        'is_commission_agent' => false,
        'commission_percent' => 0,
        'max_discount_percent' => null, // null = no personal limit
    ];

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'pin',
        'language',
        'is_active',
        'allow_login',
        'is_service_staff',
        'is_commission_agent',
        'commission_percent',
        'max_discount_percent',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
            'allow_login' => 'boolean',
            'is_service_staff' => 'boolean',
            'is_commission_agent' => 'boolean',
            'commission_percent' => 'decimal:2',
            'max_discount_percent' => 'decimal:2',
            'custom_fields' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active || ! $this->allow_login) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isSuperAdmin() || $this->can('admin.access'),
            'pos' => $this->isSuperAdmin() || $this->can('pos.access'),
            default => false,
        };
    }

    /** @return HasOne<EmployeeProfile, $this> salary data of the employee this user is (if linked) */
    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN_ROLE);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_user', 'user_id', 'location_id');
    }

    /** Contacts this user is restricted to (empty = no restriction). */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_user', 'user_id', 'contact_id');
    }

    /** Locations the user may work in; super admins / "all locations" permission see every location. */
    /** @return Builder<Location> */
    public function accessibleLocations(): Builder
    {
        if ($this->isSuperAdmin() || $this->can('locations.access_all')) {
            return Location::query();
        }

        return Location::query()->whereIn('id', $this->locations()->select('locations.id'));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
