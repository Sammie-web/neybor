<?php

namespace App\Console\Commands;

use App\Services\BookingMatcher;
use Illuminate\Console\Command;

class ExpireProviderOffers extends Command
{
    protected $signature = 'bookings:expire-provider-offers';

    protected $description = 'Expire unanswered provider offers and continue matching bookings';

    public function handle(BookingMatcher $matcher): int
    {
        $expired = $matcher->expireOffers();
        $this->info("Expired {$expired} provider offer(s).");

        return self::SUCCESS;
    }
}
