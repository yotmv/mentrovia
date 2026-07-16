<?php

namespace App\Enums;

enum AccountCapability: string
{
    case Workspace = 'workspace';
    case Project = 'project';
    case Photo = 'photo';
    case HostedAi = 'hosted_ai';
}
