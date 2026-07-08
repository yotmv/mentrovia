<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class IntakeController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.business.intake');
    }
}
