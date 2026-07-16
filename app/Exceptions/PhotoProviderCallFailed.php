<?php

namespace App\Exceptions;

use RuntimeException;

final class PhotoProviderCallFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The image provider could not complete the request.');
    }
}
