<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Filament\Admin\Support\ContactResource;
use App\Filament\NavigationGroup;
use App\Filament\Support\UserLocations;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use UnitEnum;

class EmployeeResource extends ContactResource
{
    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Payroll;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 1;

    public static function side(): ContactType
    {
        return ContactType::Employee;
    }

    public static function getModelLabel(): string
    {
        return __('Employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employees');
    }

    protected static function extraFormComponents(): array
    {
        return [
            Section::make(__('Salary data'))->columnSpanFull()->columns(3)->relationship('employeeProfile')->schema([
                TextInput::make('job_title')->label(__('Job title'))->maxLength(255),
                Select::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options())
                    ->helperText(__('A branch payroll includes only its own employees.')),
                Select::make('user_id')->label(__('System user'))->searchable()
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->unique('employee_profiles', 'user_id', ignoreRecord: true)
                    ->helperText(__('The account this employee signs in with — needed to request advances.')),
                Select::make('status')->label(__('Status'))->options(EmployeeStatus::class)->default(EmployeeStatus::Active)->required(),
                TextInput::make('base_salary')->label(__('Monthly salary'))->numeric()->minValue(0)->default(0)->required(),
                TextInput::make('fixed_allowances')->label(__('Fixed monthly allowances'))->numeric()->minValue(0)->default(0)
                    ->helperText(__('Transport, meals… added to every payroll.')),
                DatePicker::make('hire_date')->label(__('Hire date')),
                DatePicker::make('end_date')->label(__('End date')),
                TextInput::make('national_id')->label(__('National ID'))->maxLength(30),
                TextInput::make('bank_account')->label(__('Bank account'))->maxLength(60),
                Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            ]),
        ];
    }

    protected static function extraInfolistComponents(): array
    {
        return [
            Section::make(__('Salary data'))->columnSpanFull()->columns(4)->schema([
                TextEntry::make('employeeProfile.job_title')->label(__('Job title'))->placeholder('—'),
                TextEntry::make('employeeProfile.location.name')->label(__('Location'))->placeholder('—'),
                TextEntry::make('employeeProfile.status')->label(__('Status'))->badge()->placeholder(__('No salary data yet')),
                TextEntry::make('employeeProfile.hire_date')->label(__('Hire date'))->date()->placeholder('—'),
                TextEntry::make('employeeProfile.base_salary')->label(__('Monthly salary'))->numeric(2)->placeholder('—'),
                TextEntry::make('employeeProfile.fixed_allowances')->label(__('Fixed monthly allowances'))->numeric(2)->placeholder('—'),
                TextEntry::make('open_advances')->label(__('Open advances'))
                    ->state(fn (Contact $record) => (string) app(AdvanceBalances::class)->openTotal($record))->numeric(2)
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : null),
                TextEntry::make('employeeProfile.national_id')->label(__('National ID'))->placeholder('—'),
            ]),
        ];
    }

    protected static function extraTableColumns(): array
    {
        return [
            TextColumn::make('employeeProfile.job_title')->label(__('Job title'))->placeholder('—')->toggleable(),
            TextColumn::make('employeeProfile.location.name')->label(__('Location'))->placeholder('—')->toggleable(),
            TextColumn::make('employeeProfile.base_salary')->label(__('Monthly salary'))->numeric(2)->placeholder('—'),
            TextColumn::make('employeeProfile.status')->label(__('Status'))->badge()->placeholder(__('No salary data yet')),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\AdvancesRelationManager::class,
            RelationManagers\PayrollLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'view' => Pages\ViewEmployee::route('/{record}'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
            'statement' => Pages\EmployeeStatement::route('/{record}/statement'),
        ];
    }
}
