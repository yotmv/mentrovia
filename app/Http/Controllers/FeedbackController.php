<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserFeedbackRequest;
use App\Models\UserFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function create(Request $request): View
    {
        return view('pages.feedback', [
            'page' => $request->string('page')->toString(),
        ]);
    }

    public function store(StoreUserFeedbackRequest $request): RedirectResponse
    {
        UserFeedback::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return to_route('feedback.create')->with('status', __('Thanks for the feedback. We will review it as we improve the beta.'));
    }
}
