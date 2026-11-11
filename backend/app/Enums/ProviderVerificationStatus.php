<?php

namespace App\Enums;

enum ProviderVerificationStatus: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
