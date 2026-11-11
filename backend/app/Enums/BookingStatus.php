<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PendingMatching = 'pending_matching';
    case Offered = 'offered';
    case Matched = 'matched';
    case Accepted = 'accepted';
    case EnRoute = 'en_route';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoProviderAvailable = 'no_provider_available';
}
