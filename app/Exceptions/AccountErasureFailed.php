<?php

namespace App\Exceptions;

use Exception;

final class AccountErasureFailed extends Exception
{
    public static function storage(): self
    {
        return new self('The account files could not be permanently erased.');
    }

    public static function database(): self
    {
        return new self('The account data could not be permanently erased.');
    }

    public static function ownershipTransferRequired(): self
    {
        return new self('Transfer ownership before deleting a user who owns a shared account.');
    }
}
