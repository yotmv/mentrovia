<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\RecurringTaskTemplate;
use App\Services\Accounts\AccountWorkGate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecurringTaskGenerator
{
    public function __construct(private AccountWorkGate $accountWorkGate) {}

    /** @return Collection<int, BusinessTask> */
    public function generateFor(Business $business): Collection
    {
        return DB::transaction(function () use ($business): Collection {
            $account = $this->accountWorkGate->lockActiveOrFail($business->account_id);
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $templates = RecurringTaskTemplate::query()
                ->where('is_active', true)
                ->with('sourceArticle')
                ->get();
            $applicable = $templates->filter(fn (RecurringTaskTemplate $template): bool => $template->appliesTo($lockedBusiness));
            $tasks = BusinessTask::query()
                ->whereBelongsTo($lockedBusiness)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('recurring_task_template_id');
            $activeTasks = $applicable->map(function (RecurringTaskTemplate $template) use ($lockedBusiness, $tasks): BusinessTask {
                $task = $tasks->get($template->id);

                return $this->updateTask($lockedBusiness, $template, $task instanceof BusinessTask ? $task : null);
            })->values();
            $applicableIds = $applicable->modelKeys();

            foreach ($tasks as $templateId => $task) {
                if (! in_array($templateId, $applicableIds, true) && $task->is_active) {
                    $task->forceFill(['is_active' => false, 'retired_at' => now()])->save();
                }
            }

            return $activeTasks;
        }, attempts: 3);
    }

    private function updateTask(Business $business, RecurringTaskTemplate $template, ?BusinessTask $task): BusinessTask
    {
        $currentDueDate = $this->dueDateFor($template);
        $attributes = [
            'knowledge_article_id' => $template->knowledge_article_id,
            'title' => $template->title,
            'description' => $template->description,
            'category' => $template->category,
            'frequency' => $template->frequency,
            'due_rule' => $template->due_rule,
            'confidence' => $template->confidence,
            'requires_professional_review' => $template->requires_professional_review,
            'is_active' => true,
            'retired_at' => null,
        ];

        if (! $task instanceof BusinessTask) {
            return BusinessTask::create([
                'business_id' => $business->id,
                'recurring_task_template_id' => $template->id,
                ...$attributes,
                'due_on' => $currentDueDate,
            ]);
        }

        $shouldAdvance = $task->completed_at !== null
            && $task->due_on !== null
            && $currentDueDate->copy()->startOfDay()->isAfter($task->due_on);
        $shouldRefreshReactivatedDueDate = ! $task->is_active
            && $task->completed_at === null
            && ($task->due_on === null || $currentDueDate->copy()->startOfDay()->isAfter($task->due_on));
        $task->forceFill([
            ...$attributes,
            ...($shouldAdvance ? [
                'due_on' => $currentDueDate,
                'completed_at' => null,
                'notes' => null,
            ] : ($shouldRefreshReactivatedDueDate ? ['due_on' => $currentDueDate] : [])),
        ])->save();

        return $task;
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

    /** @param array<string, mixed> $dueRule */
    private function nextMonthDay(CarbonInterface $today, array $dueRule): CarbonInterface
    {
        $dueDate = $today->copy()->setDate($today->year, (int) ($dueRule['month'] ?? 1), (int) ($dueRule['day'] ?? 1));

        return $dueDate->isBefore($today) ? $dueDate->addYear() : $dueDate;
    }
}
