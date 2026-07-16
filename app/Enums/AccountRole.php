<?php

namespace App\Enums;

enum AccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
