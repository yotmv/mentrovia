<?php

namespace App\Enums;

enum BusinessProfileVersionSource: string
{
    case Onboarding = 'onboarding';
    case Manual = 'manual';
    case CsvImport = 'csv_import';
    case Workflow = 'workflow';
    case Backfill = 'backfill';
}
