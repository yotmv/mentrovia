<?php

namespace App\Exceptions;

use Exception;

final class WorkspaceErasureFailed extends Exception
{
    public static function database(): self
    {
        return new self('The workspace deletion could not be started or resumed safely.');
    }

    public static function storage(): self
    {
        return new self('The workspace files could not be verified as permanently erased.');
    }
}
