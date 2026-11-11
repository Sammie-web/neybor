<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case ServiceProvider = 'service_provider';
    case Administrator = 'administrator';
}
