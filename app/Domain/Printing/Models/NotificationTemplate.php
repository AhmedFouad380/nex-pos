<?php

namespace App\Domain\Printing\Models;

use App\Domain\Printing\Enums\NotificationChannel;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'auto_send' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'event',
        'channel',
        'subject',
        'body',
        'cc',
        'bcc',
        'auto_send',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'auto_send' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
