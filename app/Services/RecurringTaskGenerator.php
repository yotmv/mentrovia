<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\RecurringTaskTemplate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RecurringTaskGenerator
{
    /**
     * Generate or refresh all applicable recurring tasks for a business.
     *
     * @return Collection<int, BusinessTask>
     */
    public function generateFor(Business $business): Collection
    {
        return RecurringTaskTemplate::query()
            ->where('is_active', true)
            ->with('sourceArticle')
            ->get()
            ->filter(fn (RecurringTaskTemplate $template): bool => $template->appliesTo($business))
            ->map(fn (RecurringTaskTemplate $template): BusinessTask => $this->updateTask($business, $template))
            ->values();
    }

    private function updateTask(Business $business, RecurringTaskTemplate $template): BusinessTask
    {
        return BusinessTask::updateOrCreate(
            [
                'business_id' => $business->id,
                'recurring_task_template_id' => $template->id,
            ],
            [
                'knowledge_article_id' => $template->knowledge_article_id,
                'title' => $template->title,
                'description' => $template->description,
                'category' => $template->category,
                'frequency' => $template->frequency,
                'due_rule' => $template->due_rule,
                'due_on' => $this->dueDateFor($template),
                'confidence' => $template->confidence,
                'requires_professional_review' => $template->requires_professional_review,
            ],
        );
    }

    private function dueDateFor(RecurringTaskTemplate $template): CarbonInterface
    {
        $today = now()->startOfDay();

        return match ($template->due_rule['type'] ?? 'end_of_period') {
            'end_of_week' => $today->copy()->endOfWeek(),
            'end_of_month' => $today->copy()->endOfMonth(),
            'end_of_quarter' => $today->copy()->endOfQuarter(),
            'month_day' => $this->nextMonthDay($today, $template->due_rule),
            default => $today,
        };
    }

    /**
     * @param  array<string, mixed>  $dueRule
     */
    private function nextMonthDay(CarbonInterface $today, array $dueRule): CarbonInterface
    {
        $dueDate = $today->copy()->setDate(
            $today->year,
            (int) ($dueRule['month'] ?? 1),
            (int) ($dueRule['day'] ?? 1),
        );

        return $dueDate->isPast() ? $dueDate->addYear() : $dueDate;
    }
}
