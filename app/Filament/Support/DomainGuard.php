<?php

namespace App\Filament\Support;

use App\Support\DomainException;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/** Static twin of HandlesDomainExceptions, for table / modal actions defined on resources. */
final class DomainGuard
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
