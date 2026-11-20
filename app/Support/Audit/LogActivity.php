<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Every activity also keeps a readable name of its record (still shown after the record is deleted)
 * and, for web requests, the IP and browser it came from.
 */
class LogActivity extends LogActivityAction
{
    protected function transformChanges(Model $activity): void
    {
        // a new record: the empty columns say nothing
        if ($activity->getAttribute('event') === 'created' && $activity->getAttribute('attribute_changes') !== null) {
            $changes = $activity->getAttribute('attribute_changes');
            $changes['attributes'] = collect($changes['attributes'] ?? [])->reject(fn ($value) => $value === null || $value === '')->all();
            $activity->setAttribute('attribute_changes', $changes);
        }
    }

    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        $properties = collect($activity->getAttribute('properties') ?? []);
        $subject = $activity->getAttribute('subject');
        if ($subject instanceof Model && ! $properties->has('label')) {
            $properties->put('label', ActivityPresenter::recordLabel($subject));
        }
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            $request = request();
            $properties->put('ip', $request->ip());
            $properties->put('agent', mb_substr((string) $request->userAgent(), 0, 255));
        }
        $activity->setAttribute('properties', $properties);
    }
}
