<?php

namespace App\Filament\Admin\Support;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Shared\Enums\DurationUnit;
use App\Filament\NavigationGroup;
use App\Filament\Support\Avatar;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Shared customer / supplier resource. Subclasses set the side; "both" contacts appear in both lists.
 * `{side}.view_own` users only see contacts assigned to them or created by them.
 */
abstract class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Contacts;

    protected static ?string $recordTitleAttribute = 'name';

    abstract public static function side(): ContactType; // Customer or Supplier

    /** permissions prefix: customers / suppliers */
    public static function prefix(): string
    {
        return match (static::side()) {
            ContactType::Supplier => 'suppliers',
            ContactType::Employee => 'employees',
            default => 'customers',
        };
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;
        $user = auth()->user();
        $ability = match ($action) {
            'viewAny', 'view' => 'view',
            'create' => 'create',
            'update' => 'update',
            default => 'delete',
        };

        $allowed = $ability === 'view'
            ? $user?->can(static::prefix().'.view') || $user?->can(static::prefix().'.view_own')
            : (bool) $user?->can(static::prefix().'.'.$ability);

        if ($allowed && $record instanceof Contact && in_array($action, ['update', 'delete'], true) && $record->is_default) {
            $allowed = $action === 'update'; // the walk-in customer can be renamed but never deleted
        }

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        // "both" contacts show in the customer and supplier lists; employees are only employees
        $query = parent::getEloquentQuery()->whereIn('type', static::side() === ContactType::Employee ? [ContactType::Employee] : [static::side(), ContactType::Both]);
        $user = auth()->user();

        if ($user instanceof User && ! $user->can(static::prefix().'.view')) {
            $query->where(fn (Builder $q) => $q->where('created_by', $user->id)
                ->orWhereHas('users', fn (Builder $u) => $u->whereKey($user->id)));
        }

        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        $side = static::side();

        return $schema->columns(3)->components([
            Section::make(__('Contact details'))->columnSpan(2)->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('business_name')->label(__('Business name'))->maxLength(255),
                Select::make('type')->label(__('Type'))->required()->default($side)
                    ->options($side === ContactType::Employee ? [$side->value => $side->getLabel()]
                        : [$side->value => $side->getLabel(), ContactType::Both->value => ContactType::Both->getLabel()]),
                TextInput::make('code')->label(__('Code'))->maxLength(30)->unique(ignoreRecord: true)
                    ->helperText(__('Leave empty to generate automatically.')),
                TextInput::make('mobile')->label(__('Mobile'))->tel()->maxLength(30),
                TextInput::make('alt_mobile')->label(__('Alternate mobile'))->tel()->maxLength(30),
                TextInput::make('email')->label(__('Email'))->email(),
                TextInput::make('tax_number')->label(__('Tax number'))->maxLength(50),
                TextInput::make('address')->label(__('Address'))->columnSpanFull(),
                TextInput::make('city')->label(__('City')),
                TextInput::make('state')->label(__('State')),
                Textarea::make('shipping_address')->label(__('Shipping address'))->rows(2)->columnSpanFull()
                    ->visible($side === ContactType::Customer),
                Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('Terms'))->columnSpan(1)->schema([
                Select::make('customer_group_id')->label(__('Customer group'))->relationship('customerGroup', 'name')
                    ->visible($side === ContactType::Customer),
                TextInput::make('credit_limit')->label(__('Credit limit'))->numeric()->minValue(0)
                    ->helperText(__('Leave empty for no limit.'))->visible($side === ContactType::Customer),
                TextInput::make('pay_term_number')->label(__('Payment term'))->numeric()->integer()->minValue(0),
                Select::make('pay_term_unit')->label(__('Unit'))->options([DurationUnit::Day->value => DurationUnit::Day->getLabel(), DurationUnit::Month->value => DurationUnit::Month->getLabel()]),
                Toggle::make('is_active')->label(__('Active'))->default(true),
                TextInput::make('opening_balance')->label(__('Opening balance'))->numeric()->minValue(0)
                    ->helperText($side === ContactType::Supplier ? __('What we owed this supplier before starting.') : __('What this customer owed us before starting.'))
                    ->visibleOn('create'),
                DatePicker::make('opening_balance_date')->label(__('Opening balance date'))->default(now())
                    ->visible(fn (Get $get, string $operation) => $operation === 'create' && filled($get('opening_balance'))),
                Select::make('opening_balance_direction')->label(__('Balance is'))->options(BalanceDirection::class)
                    ->default($side === ContactType::Supplier ? BalanceDirection::Payable : BalanceDirection::Receivable)
                    ->visible(fn (Get $get, string $operation) => $operation === 'create' && filled($get('opening_balance')) && in_array($get('type'), [ContactType::Both->value, ContactType::Employee->value], true)),
                Select::make('user_ids')->label(__('Assigned users'))->multiple()
                    ->options(fn () => User::query()->active()->pluck('name', 'id'))
                    ->helperText(__('Users with "own contacts only" access see the contacts assigned to them.')),
            ]),
            ...static::extraFormComponents(),
        ]);
    }

    /** Extra sections a subclass adds to the form (e.g. an employee's salary data). */
    protected static function extraFormComponents(): array
    {
        return [];
    }

    /** Extra sections a subclass adds to the view page. */
    protected static function extraInfolistComponents(): array
    {
        return [];
    }

    /** Extra columns a subclass adds to the list, after the name. */
    protected static function extraTableColumns(): array
    {
        return [];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('Contact details'))->columnSpan(2)->columns(3)->schema([
                TextEntry::make('name')->label(__('Name'))->weight('bold'),
                TextEntry::make('code')->label(__('Code'))->copyable(),
                TextEntry::make('type')->label(__('Type'))->badge(),
                TextEntry::make('business_name')->label(__('Business name'))->placeholder('—'),
                TextEntry::make('mobile')->label(__('Mobile'))->placeholder('—'),
                TextEntry::make('email')->label(__('Email'))->placeholder('—'),
                TextEntry::make('tax_number')->label(__('Tax number'))->placeholder('—'),
                TextEntry::make('address')->label(__('Address'))->placeholder('—'),
                TextEntry::make('customerGroup.name')->label(__('Customer group'))->placeholder('—'),
            ]),
            Section::make(__('Balance'))->columnSpan(1)->schema([
                TextEntry::make('balance')->label(fn (Contact $r) => static::balanceLabel($r))
                    ->state(fn (Contact $r) => abs((float) $r->balance))->numeric(2)->size('lg')->weight('bold')
                    ->color(fn (Contact $r) => (float) $r->balance > 0 ? 'danger' : ((float) $r->balance < 0 ? 'warning' : 'success')),
                TextEntry::make('credit_limit')->label(__('Credit limit'))->numeric(2)->placeholder(__('No limit'))
                    ->visible(static::side() === ContactType::Customer),
                TextEntry::make('pay_term_number')->label(__('Payment term'))->placeholder('—')
                    ->formatStateUsing(fn ($state, Contact $r) => $state.' '.$r->pay_term_unit?->getLabel()),
            ]),
            ...static::extraInfolistComponents(),
        ]);
    }

    /** Positive balance = they owe us. Labels speak from the business's point of view. */
    public static function balanceLabel(Contact $contact): string
    {
        return match (true) {
            (float) $contact->balance > 0 => __('They owe us'),
            (float) $contact->balance < 0 => __('We owe them'),
            default => __('Settled'),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customerGroup'))
            ->defaultSort('name')
            ->columns([
                Avatar::column(TextColumn::make('name'))->label(__('Name'))->searchable(['name', 'business_name', 'code', 'mobile'])->sortable()
                    ->description(fn (Contact $r) => $r->business_name ?: $r->code),
                ...static::extraTableColumns(),
                TextColumn::make('mobile')->label(__('Mobile'))->placeholder('—')->copyable(),
                TextColumn::make('customerGroup.name')->label(__('Customer group'))->placeholder('—')->toggleable()
                    ->visible(static::side() === ContactType::Customer),
                TextColumn::make('balance')->label(__('Balance'))->numeric(2)->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : ((float) $state < 0 ? 'warning' : null))
                    ->description(fn (Contact $r) => (float) $r->balance != 0 ? static::balanceLabel($r) : null),
                TextColumn::make('city')->label(__('City'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label(__('Active'))->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer_group_id')->label(__('Customer group'))->relationship('customerGroup', 'name')
                    ->visible(static::side() === ContactType::Customer),
                Filter::make('owes_us')->label(__('They owe us'))->query(fn (Builder $query) => $query->where('balance', '>', 0)),
                Filter::make('we_owe')->label(__('We owe them'))->query(fn (Builder $query) => $query->where('balance', '<', 0)),
                TernaryFilter::make('is_active')->label(__('Active')),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }
}
