<?php

namespace App\Support\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/** Logins, logouts and wrong-password attempts go to the activity log under "auth". */
final class AuthActivity
{
    public const LOG = 'auth';

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, function (Login $event) {
            if ($event->user instanceof Model) {
                activity(self::LOG)->event('login')->causedBy($event->user)->performedOn($event->user)
                    ->withProperty('guard', $event->guard)->log('login');
            }
        });

        $events->listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof Model) {
                activity(self::LOG)->event('logout')->causedBy($event->user)->performedOn($event->user)->log('logout');
            }
        });

        $events->listen(Failed::class, function (Failed $event) {
            // never the password: only the name the person typed
            $username = $event->credentials['username'] ?? $event->credentials['email'] ?? $event->credentials['login'] ?? '';
            $logger = activity(self::LOG)->event('failed')->withProperty('username', mb_substr((string) $username, 0, 100));
            if ($event->user instanceof Model) {
                $logger->performedOn($event->user);
            }
            $logger->causedByAnonymous()->log('failed');
        });
    }
}
