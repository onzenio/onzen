<?php

namespace App\Enums;

enum AuthorStatus: string
{
    case Active = 'active';
    case Ineligible = 'ineligible';
}
