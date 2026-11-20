<?php

namespace App\Support;

use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Puts a notification in a user's bell right away. Filament's database notification is queued, and a
 * shared host may have no queue worker running — then nothing would ever arrive.
 */
final class BellNotification
{
    /** @param iterable<User> $users */
    public static function send(Notification $notification, iterable $users): void
    {
        foreach ($users as $user) {
            $user->notifyNow($notification->toDatabase());
        }
    }
}
