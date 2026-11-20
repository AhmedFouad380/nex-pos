<?php

namespace App\Support\Audit;

use App\Domain\CRM\Models\Contact;
use App\Filament\Admin\Support\ContactResource;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/** Turns raw activity rows into words: record names, field labels, readable old → new values. */
final class ActivityPresenter
{
    /** Columns that name a record, in order of preference. */
    private const IDENTIFIERS = ['number', 'invoice_no', 'ref_no', 'draft_no', 'name', 'title', 'username', 'code'];

    private const CONTACT_KEYS = ['contact_id', 'customer_id', 'supplier_id'];

    public static function recordLabel(Model $model): string
    {
        $attributes = $model->getAttributes();
        $name = null;
        foreach (self::IDENTIFIERS as $column) {
            if (filled($attributes[$column] ?? null)) {
                $name = (string) $attributes[$column];
                break;
            }
        }
        // payroll lines, employee profiles… are named after their employee
        foreach (self::CONTACT_KEYS as $column) {
            if (filled($attributes[$column] ?? null) && ! $model instanceof Contact) {
                $contact = Contact::query()->whereKey($attributes[$column])->value('name');
                if ($contact !== null) {
                    $name = $name === null ? $contact : $name.' — '.$contact;
                }
                break;
            }
        }

        return $name ?? '#'.$model->getKey();
    }

    public static function subjectLabel(?string $logName): string
    {
        if (blank($logName)) {
            return __('audit.subjects.default');
        }
        $key = 'audit.subjects.'.$logName;

        return __($key) !== $key ? __($key) : Str::headline($logName);
    }

    public static function eventLabel(?string $event): string
    {
        $key = 'audit.events.'.$event;

        return __($key) !== $key ? __($key) : Str::headline((string) $event);
    }

    public static function eventColor(?string $event): string
    {
        return match ($event) {
            'created', 'login' => 'success',
            'updated' => 'info',
            'deleted', 'failed' => 'danger',
            'restored' => 'warning',
            default => 'gray',
        };
    }

    public static function causerName(Activity $activity): string
    {
        $causer = $activity->causer;
        if ($causer === null) {
            return $activity->causer_type === null ? __('audit.system') : __('audit.deleted_user');
        }
        $name = (string) ($causer->getAttributes()['name'] ?? '#'.$causer->getKey());

        return $activity->causer_type === 'wallet_device' ? __('audit.phone', ['name' => $name]) : $name;
    }

    public static function fieldLabel(string $field): string
    {
        $key = 'audit.fields.'.$field;
        if (__($key) !== $key) {
            return __($key);
        }
        $words = Str::headline(Str::beforeLast($field, '_id') ?: $field);
        $translated = __($words);

        return is_string($translated) ? $translated : $words;
    }

    /**
     * The changed fields of an activity.
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public static function changes(Activity $activity, bool $resolveRelations = false): array
    {
        $changes = $activity->attribute_changes;
        $new = (array) ($changes['attributes'] ?? []);
        $old = (array) ($changes['old'] ?? []);
        $model = self::modelFor($activity->subject_type);

        $rows = [];
        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $field) {
            $rows[] = [
                'field' => $field,
                'label' => self::fieldLabel($field),
                'old' => array_key_exists($field, $old) ? self::value($model, $field, $old[$field], $resolveRelations) : '',
                'new' => array_key_exists($field, $new) ? self::value($model, $field, $new[$field], $resolveRelations) : '',
            ];
        }

        return $rows;
    }

    /** Short "field, field, field" summary for the table. */
    public static function summary(Activity $activity): string
    {
        if ($activity->event === 'failed') {
            return __('audit.failed_as', ['name' => (string) $activity->getProperty('username')]);
        }
        $fields = collect(self::changes($activity))->pluck('label');
        if ($activity->event !== 'updated' || $fields->isEmpty()) {
            return '';
        }

        return $fields->take(4)->implode('، ').($fields->count() > 4 ? ' …(+'.($fields->count() - 4).')' : '');
    }

    public static function subjectUrl(Activity $activity): ?string
    {
        $subject = $activity->subject;
        if (! $subject instanceof Model) {
            return null;
        }

        return rescue(function () use ($subject) {
            foreach (Filament::getResources() as $resource) {
                if ($resource::getModel() !== $subject::class) {
                    continue;
                }
                if (is_subclass_of($resource, ContactResource::class) && $subject instanceof Contact && $resource::side() !== $subject->type) {
                    continue;
                }
                foreach (['view' => 'canView', 'edit' => 'canEdit'] as $page => $ability) {
                    if ($resource::hasPage($page) && $resource::$ability($subject)) {
                        return $resource::getUrl($page, ['record' => $subject]);
                    }
                }
            }

            return null;
        }, null, false);
    }

    private static function modelFor(?string $type): ?Model
    {
        $class = $type === null ? null : Relation::getMorphedModel($type);

        return $class !== null && class_exists($class) ? new $class : null;
    }

    private static function value(?Model $model, string $field, mixed $value, bool $resolveRelations): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $cast = $model?->getCasts()[$field] ?? null;
        if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            $case = $cast::tryFrom($value);
            if ($case instanceof HasLabel) {
                return (string) $case->getLabel();
            }
        }
        if (in_array($cast, ['bool', 'boolean'], true)) {
            return $value ? __('Yes') : __('No');
        }
        if ($resolveRelations && $model !== null && str_ends_with($field, '_id')) {
            $related = self::related($model, $field, $value);
            if ($related !== null) {
                return $related;
            }
        }
        if (is_numeric($value) && str_contains((string) $value, '.')) {
            return rtrim(rtrim((string) $value, '0'), '.');
        }

        return (string) $value;
    }

    private static function related(Model $model, string $field, mixed $id): ?string
    {
        $method = Str::camel(Str::beforeLast($field, '_id'));
        if (! method_exists($model, $method)) {
            return null;
        }
        try {
            $relation = $model->{$method}();
            if (! $relation instanceof BelongsTo) {
                return null;
            }
            $related = $relation->getRelated()->newQuery()->find($id);
        } catch (Throwable) {
            return null;
        }

        return $related instanceof Model ? self::recordLabel($related) : null;
    }
}
