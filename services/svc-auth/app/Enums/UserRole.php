<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case RESIDENT = 'resident';
}
