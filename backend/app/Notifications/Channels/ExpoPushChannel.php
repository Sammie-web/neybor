<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExpoPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpoPush')) {
            throw new RuntimeException('Expo notification payload is not defined.');
        }

        $tokens = $notifiable->pushTokens()->pluck('token')->all();
        foreach (array_chunk($tokens, 100) as $batch) {
            $messages = array_map(
                fn (string $token): array => ['to' => $token, ...$notification->toExpoPush($notifiable)],
                $batch,
            );
            $response = Http::timeout(10)
                ->acceptJson()
                ->post('https://exp.host/--/api/v2/push/send', $messages);
            if (! $response->successful()) {
                throw new RuntimeException('Expo push delivery failed with HTTP '.$response->status().'.');
            }

            $tickets = $response->json('data');
            if (! is_array($tickets) || count($tickets) !== count($messages)) {
                throw new RuntimeException('Expo push delivery returned an invalid ticket response.');
            }
            foreach ($tickets as $ticket) {
                if (! is_array($ticket) || ($ticket['status'] ?? null) !== 'ok') {
                    $reason = is_array($ticket) ? ($ticket['message'] ?? 'unknown error') : 'malformed ticket';
                    Log::warning('Expo push notification was rejected.', [
                        'notification_id' => $notification->id,
                        'reason' => $reason,
                    ]);
                    throw new RuntimeException('Expo push delivery failed: '.$reason);
                }
            }
        }
    }
}
