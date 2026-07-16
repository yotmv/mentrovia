<?php

namespace App\Http\Controllers;

use App\Enums\GuideTopic;
use App\Models\KnowledgeArticle;
use App\Services\Accounts\CurrentAccount;
use App\Services\RoadmapBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuidesController extends Controller
{
    public function index(Request $request, CurrentAccount $currentAccount): View|RedirectResponse
    {
        if ($currentAccount->account()->business === null) {
            return to_route('onboarding.welcome');
        }

        return view('pages.guides.index', ['guides' => GuideTopic::cases()]);
    }

    public function show(Request $request, GuideTopic $guide, RoadmapBuilder $roadmap, CurrentAccount $currentAccount): View|RedirectResponse
    {
        if ($guide === GuideTopic::Banking) {
            return to_route('banking-setup');
        }

        if ($guide === GuideTopic::OwnerPay) {
            return to_route('owner-pay');
        }

        $business = $currentAccount->account()->business;

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        return view('pages.guides.show', [
            'business' => $business,
            'guide' => $guide,
            'roadmapItems' => $roadmap->build($business)
                ->filter(fn ($item): bool => in_array($item->key, $guide->roadmapItemKeys(), true))
                ->values(),
            'tasks' => $business->tasks()
                ->active()
                ->with('sourceArticle')
                ->whereIn('category', array_map(fn ($category): string => $category->value, $guide->taskCategories()))
                ->whereNull('completed_at')
                ->orderBy('due_on')
                ->orderBy('title')
                ->limit(4)
                ->get(),
            'articles' => KnowledgeArticle::query()
                ->published()
                ->with('sources')
                ->whereIn('category', array_map(fn ($category): string => $category->value, $guide->articleCategories()))
                ->orderBy('title')
                ->get(),
        ]);
    }
}
