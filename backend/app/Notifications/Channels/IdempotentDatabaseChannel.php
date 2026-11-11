<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use RuntimeException;

class IdempotentDatabaseChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDatabase') || ! method_exists($notifiable, 'notifications')) {
            throw new RuntimeException('The idempotent database notification payload is not defined.');
        }

        $type = method_exists($notification, 'databaseType')
            ? $notification->databaseType($notifiable)
            : $notification::class;

        $notifiable->notifications()->firstOrCreate(
            ['id' => $notification->id],
            [
                'type' => $type,
                'data' => $notification->toDatabase($notifiable),
                'read_at' => null,
            ],
        );
    }
}
