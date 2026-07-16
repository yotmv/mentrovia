<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory;

final class ByokHttpFactory extends Factory
{
    public function __construct()
    {
        parent::__construct(dispatcher: null);
    }
}
