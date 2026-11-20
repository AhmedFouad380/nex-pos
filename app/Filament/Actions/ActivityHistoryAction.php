<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * "History" button on a record page: every change made to the record, by whom and when (from the activity log).
 * Pass $related for records whose details live in other audited models (e.g. an employee's salary data).
 */
final class ActivityHistoryAction
{
    /** @param  Closure|null  $related  receives the page record, returns more audited records to include */
    public static function make(?Closure $related = null): Action
    {
        return Action::make('activityHistory')->label(__('History'))->icon('heroicon-o-clock')->color('gray')
            ->visible(fn () => auth()->user()?->can('activity_log.view') ?? false)
            ->slideOver()->modalWidth('2xl')
            ->modalHeading(__('History'))
            ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
            ->modalContent(function (Model $record) use ($related) {
                $subjects = collect([$record, ...($related ? $related($record) : [])])->filter();
                $activities = Activity::query()->with('causer')
                    ->where(function ($query) use ($subjects) {
                        foreach ($subjects as $subject) {
                            $query->orWhere(fn ($q) => $q->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey()));
                        }
                    })
                    ->where('log_name', '!=', 'auth')
                    ->latest('id')->limit(100)->get();

                return view('filament.admin.audit.history', ['activities' => $activities, 'mainLog' => $record->getMorphClass()]);
            });
    }
}
