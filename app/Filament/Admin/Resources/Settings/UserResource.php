<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Actions\LinkUserToEmployee;
use App\Filament\Admin\Resources\Settings\Pages\ManageUsers;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\Avatar;
use App\Filament\Support\DomainGuard;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = User::class;

    protected static string $permissionPrefix = 'users';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    /** Only super admins may grant / edit the super-admin role. */
    public static function roleOptions(): array
    {
        return Role::query()
            ->when(! auth()->user()?->isSuperAdmin(), fn ($q) => $q->where('name', '!=', User::SUPER_ADMIN_ROLE))
            ->pluck('name', 'id')
            ->map(fn ($name) => RoleResource::label($name))
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('username')->label(__('Username'))->maxLength(60)->unique(ignoreRecord: true),
                TextInput::make('email')->label(__('Email'))->email()->required()->unique(ignoreRecord: true),
                TextInput::make('phone')->label(__('Phone'))->tel(),
                TextInput::make('password')->label(__('Password'))->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))->minLength(8)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('Leave empty to keep the current password.') : null),
                TextInput::make('pin')->label(__('POS PIN'))->password()->revealable()->numeric()->length(4)
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(__('4 digits for quick cashier login. Leave empty to keep it.')),
            ]),
            Section::make(__('Access'))->columns(2)->columnSpanFull()->schema([
                Select::make('roles')->label(__('Roles'))->multiple()->required()
                    ->relationship('roles', 'name')->options(fn () => static::roleOptions())->preload()
                    // server-side guard: hiding super-admin from the list is not enough against a crafted request
                    ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                        if (array_diff((array) $value, array_keys(static::roleOptions())) !== []) {
                            $fail(__('You are not allowed to assign this role.'));
                        }
                    }]),
                Select::make('locations')->label(__('Locations'))->multiple()->relationship('locations', 'name')->preload()
                    ->helperText(__('Ignored for roles with access to all locations.')),
                TextInput::make('max_discount_percent')->label(__('Max discount allowed'))->numeric()->suffix('%')->minValue(0)->maxValue(100),
                Select::make('language')->label(__('Language'))->options(['ar' => 'العربية', 'en' => 'English'])->default('ar'),
                Toggle::make('is_active')->label(__('Active'))->default(true),
                Toggle::make('allow_login')->label(__('Can log in'))->default(true),
                Toggle::make('is_service_staff')->label(__('Service staff (waiter)')),
                Toggle::make('is_commission_agent')->label(__('Commission agent'))->live(),
                TextInput::make('commission_percent')->label(__('Commission'))->numeric()->suffix('%')
                    ->visible(fn ($get) => $get('is_commission_agent')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'locations', 'employeeProfile.contact']))
            ->columns([
                Avatar::column(TextColumn::make('name'))->label(__('Name'))->searchable(['name', 'username', 'email'])
                    ->description(fn (User $r) => collect([$r->username, str_ends_with((string) $r->email, '@import.invalid') ? null : $r->email])->filter()->implode(' · ')),
                TextColumn::make('roles.name')->label(__('Roles'))->badge()
                    ->formatStateUsing(fn ($state) => RoleResource::label($state)),
                TextColumn::make('locations.name')->label(__('Locations'))->badge()->limitList(3),
                TextColumn::make('employeeProfile.contact.name')->label(__('Employee'))->placeholder(__('Not linked'))->toggleable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                IconColumn::make('allow_login')->label(__('Can log in'))->boolean()
                    ->tooltip(fn (User $r) => $r->allow_login ? null : __('Cannot log in until login is activated.')),
            ])
            ->filters([
                SelectFilter::make('roles')->label(__('Role'))->relationship('roles', 'name'),
                Filter::make('cannot_log_in')->label(__('Cannot log in'))->query(fn (Builder $query) => $query->where('allow_login', false)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // users imported from the old system: their passwords could not be carried over
                Action::make('activateLogin')->label(__('Activate login'))->icon('heroicon-o-key')->color('success')
                    ->visible(fn (User $r) => ! $r->allow_login && $r->deleted_at === null && static::canEdit($r)
                        && (! $r->isSuperAdmin() || auth()->user()?->isSuperAdmin()))
                    ->modalHeading(fn (User $r) => __('Activate login for :name', ['name' => $r->name]))
                    ->modalDescription(__('Set a new password; the user can then sign in with the username or e-mail below.'))
                    ->schema([
                        TextInput::make('username')->label(__('Username'))->required()->maxLength(60)
                            ->default(fn (User $record) => $record->username)
                            ->unique('users', 'username', ignoreRecord: true),
                        TextInput::make('password')->label(__('Password'))->password()->revealable()->required()->minLength(6),
                        TextInput::make('pin')->label(__('POS PIN'))->password()->revealable()->numeric()->length(4)
                            ->helperText(__('Optional: 4 digits for the quick cashier login.')),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(array_filter([
                            'username' => $data['username'], 'password' => $data['password'], 'pin' => $data['pin'] ?? null,
                            'allow_login' => true, 'is_active' => true,
                        ], fn ($value) => $value !== null && $value !== ''));
                        Notification::make()->success()->title(__('Login activated'))->send();
                    }),
                // the employee this login belongs to: needed to request an advance from the till
                Action::make('linkEmployee')->label(__('Link to employee'))->icon('heroicon-o-link')->color('gray')
                    ->visible(fn (User $r) => $r->deleted_at === null && static::canEdit($r))
                    ->fillForm(fn (User $record) => ['contact_id' => $record->employeeProfile?->contact_id])
                    ->schema([
                        Select::make('contact_id')->label(__('Employee'))->searchable()
                            ->options(fn () => Contact::query()->where('type', ContactType::Employee)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->helperText(__('Empty = not linked.')),
                    ])
                    ->action(function (User $record, array $data) {
                        $employee = filled($data['contact_id'] ?? null) ? Contact::query()->findOrFail($data['contact_id']) : null;
                        DomainGuard::run(fn () => app(LinkUserToEmployee::class)->handle($record, $employee));
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                EditAction::make()->visible(fn (User $r) => ! $r->isSuperAdmin() || auth()->user()?->isSuperAdmin()),
                DeleteAction::make()->visible(fn (User $r) => $r->id !== auth()->id() && (! $r->isSuperAdmin() || auth()->user()?->isSuperAdmin())),
                RestoreAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
