<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Filament\Admin\Resources\Settings\Pages\ManageRoles;
use App\Filament\NavigationGroup;
use App\Filament\Support\DomainGuard;
use App\Support\DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** الأدوار والصلاحيات. The super admin role passes every check and is not edited here. */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 11;

    public static function getModelLabel(): string
    {
        return __('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Roles and permissions');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('roles.manage');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->getAttribute('name') !== 'super-admin';
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record) && ! array_key_exists($record->getAttribute('name'), config('permissions.roles'));
    }

    /**
     * "users.view" → "عرض المستخدمين". Read from the array: __('permissions.permissions.users.view') would
     * split "users.view" at the dot and miss the translation.
     */
    public static function permissionLabel(string $permission): string
    {
        $labels = trans('permissions.permissions');

        return is_array($labels) && is_string($labels[$permission] ?? null) ? $labels[$permission] : $permission;
    }

    public static function label(string $name): string
    {
        $key = "permissions.roles.{$name}";

        return __($key) === $key ? $name : __($key);
    }

    public static function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (config('permissions.groups') as $group => $permissions) {
            $sections[] = Section::make(__("permissions.groups.{$group}"))->collapsible()->schema([
                CheckboxList::make("permissions.{$group}")->hiddenLabel()->columns(3)->bulkToggleable()
                    ->options(collect($permissions)->mapWithKeys(fn ($p) => [$p => self::permissionLabel($p)])->all()),
            ]);
        }

        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(100)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('guard_name', 'web'))
                ->disabled(fn (?Role $record) => $record !== null && array_key_exists($record->name, config('permissions.roles')))
                ->dehydrated()
                ->columnSpanFull(),
            ...$sections,
        ]);
    }

    /** Role → form state: permissions grouped like the config. @return array<string, mixed> */
    public static function toForm(Role $role): array
    {
        $granted = $role->permissions()->pluck('name')->all();
        $groups = [];
        foreach (config('permissions.groups') as $group => $permissions) {
            $groups[$group] = array_values(array_intersect($permissions, $granted));
        }

        return ['name' => $role->name, 'permissions' => $groups];
    }

    /** @param array<string, mixed> $data */
    public static function save(array $data, ?Role $role = null): Role
    {
        if ($role?->name === 'super-admin') {
            throw new DomainException(__('The super admin role cannot be changed.'));
        }

        return DB::transaction(function () use ($data, $role) {
            $role ??= Role::query()->create(['name' => $data['name'], 'guard_name' => 'web']);
            if (! array_key_exists($role->name, config('permissions.roles'))) {
                $role->update(['name' => $data['name']]);
            }
            $known = collect(config('permissions.groups'))->flatten()->all();
            $role->syncPermissions(array_values(array_intersect(collect($data['permissions'] ?? [])->flatten()->all(), $known)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->formatStateUsing(fn (string $state) => self::label($state)),
                TextColumn::make('permissions_count')->counts('permissions')->label(__('Granted permissions'))
                    ->formatStateUsing(fn ($state, Role $record) => $record->name === 'super-admin' ? __('All') : $state),
                TextColumn::make('users_count')->counts('users')->label(__('Users')),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Role $record) => self::canEdit($record))
                    ->fillForm(fn (Role $record) => self::toForm($record))
                    ->using(fn (Role $record, array $data) => DomainGuard::run(fn () => self::save($data, $record))),
                DeleteAction::make()->visible(fn (Role $record) => self::canDelete($record))->before(function (Role $record, DeleteAction $action) {
                    if ($record->users()->exists()) {
                        DomainGuard::run(fn () => throw new DomainException(__('This role is assigned to users.')));
                    }
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
