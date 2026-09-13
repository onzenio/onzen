<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Operator = 'operator';
    case User = 'user';
}
