<?php

namespace App\Notifications;

use App\Models\ProviderOffer;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\Channels\IdempotentDatabaseChannel;
use App\Notifications\Concerns\HasStableNotificationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProviderOfferReceived extends Notification implements ShouldQueue
{
    use HasStableNotificationId, Queueable;

    public function __construct(private readonly ProviderOffer $offer) {}

    public function via(object $notifiable): array
    {
        $this->useStableNotificationId('provider-offer:'.$this->offer->id.':user:'.$notifiable->getKey());

        return [IdempotentDatabaseChannel::class, 'broadcast', ExpoPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New service request',
            'body' => 'A nearby customer needs '.$this->offer->service->name.'. Respond before the offer expires.',
            'booking_id' => $this->offer->booking_id,
            'offer_id' => $this->offer->id,
            'expires_at' => $this->offer->expires_at->toIso8601String(),
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function toExpoPush(object $notifiable): array
    {
        return [
            'title' => 'New service request',
            'body' => 'A nearby customer needs '.$this->offer->service->name.'. Respond before the offer expires.',
            'data' => [
                'booking_id' => $this->offer->booking_id,
                'offer_id' => $this->offer->id,
                'type' => 'provider_offer',
                'notification_id' => $this->id,
            ],
            'sound' => 'default',
        ];
    }
}
