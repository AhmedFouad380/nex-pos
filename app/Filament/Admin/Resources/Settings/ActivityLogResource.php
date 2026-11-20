<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Filament\Admin\Resources\Settings\Pages\ListActivities;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Models\User;
use App\Support\Audit\ActivityPresenter;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/** سجل النشاط: who did what, when, from where — and what every changed field was before and after. Read only. */
class ActivityLogResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity-log';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('Activity entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Activity log');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'activity_log.view' : false;
    }

    /** @return array<string, string> every kind of record that is logged */
    public static function sectionOptions(): array
    {
        $subjects = trans('audit.subjects');

        return collect(is_array($subjects) ? array_keys($subjects) : [])->reject(fn ($k) => $k === 'default')
            ->mapWithKeys(fn ($k) => [$k => ActivityPresenter::subjectLabel($k)])->sort()->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                TextEntry::make('created_at')->label(__('Date'))->dateTime('Y-m-d H:i:s'),
                TextEntry::make('causer_name')->label(__('User'))->state(fn (Activity $record) => ActivityPresenter::causerName($record)),
                TextEntry::make('event')->label(__('Action'))->badge()
                    ->formatStateUsing(fn (?string $state) => ActivityPresenter::eventLabel($state))
                    ->color(fn (?string $state) => ActivityPresenter::eventColor($state)),
                TextEntry::make('log_name')->label(__('Section'))->formatStateUsing(fn (?string $state) => ActivityPresenter::subjectLabel($state)),
                TextEntry::make('record')->label(__('Record'))->placeholder('—')
                    ->state(fn (Activity $record) => $record->getProperty('label'))
                    ->url(fn (Activity $record) => ActivityPresenter::subjectUrl($record)),
                TextEntry::make('ip')->label(__('IP address'))->placeholder('—')->state(fn (Activity $record) => $record->getProperty('ip')),
                TextEntry::make('agent')->label(__('Browser'))->placeholder('—')->columnSpanFull()->size('xs')->color('gray')
                    ->state(fn (Activity $record) => $record->getProperty('agent')),
            ]),
            ViewEntry::make('changes')->hiddenLabel()->columnSpanFull()->view('filament.admin.audit.changes')
                ->state(fn (Activity $record) => ActivityPresenter::changes($record, resolveRelations: true))
                ->visible(fn (Activity $record) => ActivityPresenter::changes($record) !== []),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('Y-m-d H:i')->sortable()
                    ->description(fn (Activity $record) => $record->created_at?->diffForHumans()),
                TextColumn::make('causer_name')->label(__('User'))->state(fn (Activity $record) => ActivityPresenter::causerName($record)),
                TextColumn::make('event')->label(__('Action'))->badge()
                    ->formatStateUsing(fn (?string $state) => ActivityPresenter::eventLabel($state))
                    ->color(fn (?string $state) => ActivityPresenter::eventColor($state)),
                TextColumn::make('log_name')->label(__('Section'))->formatStateUsing(fn (?string $state) => ActivityPresenter::subjectLabel($state)),
                TextColumn::make('record')->label(__('Record'))->placeholder('—')
                    ->state(fn (Activity $record) => $record->getProperty('label'))
                    ->url(fn (Activity $record) => ActivityPresenter::subjectUrl($record)),
                TextColumn::make('summary')->label(__('Changed fields'))->placeholder('—')->wrap()->color('gray')
                    ->state(fn (Activity $record) => ActivityPresenter::summary($record) ?: null),
                TextColumn::make('ip')->label(__('IP address'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Activity $record) => $record->getProperty('ip')),
            ])
            ->filters([
                SelectFilter::make('causer')->label(__('User'))->searchable()
                    ->options(fn () => User::withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $query, $id) => $query->where('causer_type', 'user')->where('causer_id', $id))),
                SelectFilter::make('log_name')->label(__('Section'))->options(fn () => self::sectionOptions())->multiple(),
                SelectFilter::make('event')->label(__('Action'))->options(function () {
                    $events = trans('audit.events');

                    return collect(is_array($events) ? array_keys($events) : [])->mapWithKeys(fn ($e) => [$e => ActivityPresenter::eventLabel($e)])->all();
                }),
                Filter::make('date')->columns(2)->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()->modalWidth('3xl')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivities::route('/')];
    }
}
