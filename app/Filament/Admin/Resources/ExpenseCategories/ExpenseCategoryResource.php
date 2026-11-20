<?php

namespace App\Filament\Admin\Resources\ExpenseCategories;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Filament\Admin\Resources\ExpenseCategories\Pages\ManageExpenseCategories;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpenseCategoryResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = ExpenseCategory::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 41;

    public static function getModelLabel(): string
    {
        return __('Expense category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expense categories');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'expenses.view' : 'accounts.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('code')->label(__('Code'))->maxLength(30),
            Select::make('parent_id')->label(__('Parent category'))->relationship('parent', 'name'),
            Select::make('account_id')->label(__('Expense account'))->options(fn () => AccountOptions::expenses())->searchable()
                ->helperText(__('Where this kind of expense is posted. Empty = miscellaneous expenses.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('code')->label(__('Code')),
                TextColumn::make('parent.name')->label(__('Parent category'))->placeholder('—'),
                TextColumn::make('account.name')->label(__('Expense account'))->placeholder('—'),
                TextColumn::make('expenses_count')->counts('expenses')->label(__('Expenses')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (ExpenseCategory $record) => ! $record->expenses()->exists() && ! $record->children()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseCategories::route('/')];
    }
}
