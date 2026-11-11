<?php

namespace App\Jobs;

use App\Services\BookingMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class MatchBookingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 20, 60];

    public function __construct(public readonly int $bookingId) {}

    public function handle(BookingMatcher $matcher): void
    {
        $matcher->offerNext($this->bookingId);
    }

    public function failed(Throwable $exception): void
    {
        app(BookingMatcher::class)->markFailed($this->bookingId, $exception);
    }
}
