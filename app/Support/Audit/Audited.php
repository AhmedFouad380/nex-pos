<?php

namespace App\Support\Audit;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * سجل النشاط: records who created / changed / deleted this model and which columns changed (old → new).
 * Log name = the morph alias (e.g. "sale"), so the activity log can be filtered by kind of record.
 *
 * Override auditExcept() for columns the system keeps up to date by itself (cached balances…),
 * and shouldAudit() to skip records that are not worth logging.
 */
trait Audited
{
    use LogsActivity {
        shouldLogEvent as protected spatieShouldLogEvent;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->logExcept([...$this->getHidden(), ...$this->auditExcept()])
            ->useLogName($this->getMorphClass());
    }

    /** @return list<string> columns that change by themselves and would only add noise */
    protected function auditExcept(): array
    {
        return [];
    }

    protected function shouldAudit(): bool
    {
        return true;
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        return $this->shouldAudit() && $this->spatieShouldLogEvent($eventName);
    }
}
