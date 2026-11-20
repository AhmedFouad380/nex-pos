<?php

namespace App\Filament\Concerns;

use App\Support\DomainException;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/** Turns a business-rule violation into a red notification and stops the page action (form keeps its input). */
trait HandlesDomainExceptions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function rescueDomain(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
