<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTaskRequest;
use App\Models\Business;
use App\Models\BusinessTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $period = $this->period((string) $request->query('period', 'week'));

        return view('pages.tasks.index', [
            'business' => $business,
            'period' => $period,
            'tabs' => $this->tabs(),
            'tasks' => $business === null ? collect() : $this->tasksForPeriod($business, $period),
        ]);
    }

    public function update(UpdateTaskRequest $request, BusinessTask $task): RedirectResponse
    {
        $validated = $request->validated();
        $completed = $request->boolean('completed');

        DB::transaction(function () use ($task, $validated, $completed): void {
            $completedAt = $task->completed_at ?? now();

            $task->forceFill([
                'completed_at' => $completed ? $completedAt : null,
                'notes' => $validated['notes'] ?? null,
            ])->save();

            if ($completed) {
                $task->completions()->updateOrCreate(
                    ['completed_for' => $task->due_on ?? now()->toDateString()],
                    [
                        'business_id' => $task->business_id,
                        'completed_at' => $completedAt,
                        'notes' => $validated['notes'] ?? null,
                    ],
                );
            }
        });

        return back();
    }

    private function period(string $period): string
    {
        return in_array($period, array_keys($this->tabs()), true) ? $period : 'week';
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            'week' => 'This week',
            'month' => 'This month',
            'quarter' => 'This quarter',
            'year' => 'This year',
            'all' => 'All tasks',
        ];
    }

    /**
     * @return Collection<int, BusinessTask>
     */
    private function tasksForPeriod(Business $business, string $period): Collection
    {
        $query = $business->tasks()
            ->with('sourceArticle')
            ->orderBy('due_on')
            ->orderBy('title');

        match ($period) {
            'week' => $query->whereBetween('due_on', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereBetween('due_on', [now()->startOfMonth(), now()->endOfMonth()]),
            'quarter' => $query->whereBetween('due_on', [now()->startOfQuarter(), now()->endOfQuarter()]),
            'year' => $query->whereBetween('due_on', [now()->startOfYear(), now()->endOfYear()]),
            default => null,
        };

        return $query->get();
    }
}
