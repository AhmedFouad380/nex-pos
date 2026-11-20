<?php

namespace App\Filament\Admin\Resources\CashSessions;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Filament\Admin\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Admin\Resources\CashSessions\Pages\ViewCashSession;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\NavigationGroup;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashSessionResource extends Resource
{
    use AuthorizesWithPermissions;
    use HasNavigationCount;

    protected static ?string $model = CashSession::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CashManagement;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Shift');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Shifts');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'cash_register.view' : false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user instanceof User && ! $user->isSuperAdmin() && ! $user->can('locations.access_all')
            ? $query->whereHas('cashRegister', fn ($q) => $q->whereIn('location_id', $user->locations()->select('locations.id')))
            : $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cashRegister.location', 'user']))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('cashRegister.location.name')->label(__('Location')),
                TextColumn::make('user.name')->label(__('Cashier'))->searchable(),
                TextColumn::make('opened_at')->label(__('Opened at'))->dateTime()->sortable(),
                TextColumn::make('closed_at')->label(__('Closed at'))->dateTime()->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('expected_cash')->label(__('Expected in drawer'))->numeric(2)->placeholder('—'),
                TextColumn::make('counted_cash')->label(__('Counted cash'))->numeric(2)->placeholder('—'),
                TextColumn::make('difference')->label(__('Difference'))->numeric(2)->placeholder('—')
                    ->color(fn ($state) => $state === null ? null : ((float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'warning' : 'success'))),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(CashSessionStatus::class),
                SelectFilter::make('user_id')->label(__('Cashier'))->relationship('user', 'name'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->where('status', CashSessionStatus::Open);
    }

    protected static function navigationCountColor(): string
    {
        return 'success';
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Open shifts');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'view' => ViewCashSession::route('/{record}'),
        ];
    }
}
