<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Filament\Admin\Resources\Settings\Pages\ManagePaymentMethods;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** طرق الدفع and the cash box / bank each one pays into. */
class PaymentMethodResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = PaymentMethod::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('Payment method');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payment methods');
    }

    protected static function permissionFor(string $action): string|false
    {
        return 'settings.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            Select::make('type')->label(__('Type'))->required()
                ->options(collect(PaymentMethodType::cases())->reject(fn ($t) => $t === PaymentMethodType::Advance)
                    ->mapWithKeys(fn ($t) => [$t->value => $t->getLabel()])),
            Select::make('account_id')->label(__('Paid into account'))->options(fn () => AccountOptions::money())->searchable()->required()
                ->helperText(__('The cash box or bank that receives (or pays) the money.')),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(0),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // "advance" is the internal method used to apply a customer's credit — not configurable
            ->modifyQueryUsing(fn ($query) => $query->where('type', '!=', PaymentMethodType::Advance))
            ->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('account.name')->label(__('Paid into account'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (PaymentMethod $r) => ! $r->payments()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
