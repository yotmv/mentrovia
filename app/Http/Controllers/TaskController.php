<?php

namespace App\Http\Controllers;

use App\Enums\AccountCapability;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request, CurrentAccount $currentAccount): View
    {
        $business = $currentAccount->account()->business;
        $period = $this->period((string) $request->query('period', 'week'));

        return view('pages.tasks.index', [
            'business' => $business,
            'period' => $period,
            'tabs' => $this->tabs(),
            'tasks' => $business === null ? collect() : $this->tasksForPeriod($business, $period),
        ]);
    }

    public function update(UpdateTaskRequest $request, BusinessTask $task, AccountMutationGate $accountMutationGate): RedirectResponse
    {
        $validated = $request->validated();
        $completed = $request->boolean('completed');

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        DB::transaction(function () use ($task, $validated, $completed, $accountMutationGate, $user): void {
            $accountId = Business::query()->whereKey($task->business_id)->value('account_id');
            abort_unless(is_numeric($accountId), 404);
            $accountMutationGate->lockMemberOrFail((int) $accountId, $user->id, AccountCapability::Workspace);
            $lockedTask = BusinessTask::query()
                ->whereKey($task->id)
                ->whereHas('business', fn (Builder $query): Builder => $query->where('account_id', $accountId))
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedTask->is_active, 403);
            $completedAt = $lockedTask->completed_at ?? now();

            $lockedTask->forceFill([
                'completed_at' => $completed ? $completedAt : null,
                'notes' => $validated['notes'] ?? null,
            ])->save();

            if ($completed) {
                $lockedTask->completions()->firstOrCreate(
                    ['completed_for' => $lockedTask->due_on ?? now()->toDateString()],
                    [
                        'business_id' => $lockedTask->business_id,
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
            ->active()
            ->with('sourceArticle')
            ->orderBy('due_on')
            ->orderBy('title');

        $bounds = match ($period) {
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => null,
        };

        if ($bounds !== null) {
            $query->where(function (Builder $query) use ($bounds): void {
                $query->whereBetween('due_on', $bounds)
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('completed_at')
                            ->whereDate('due_on', '<', now()->toDateString());
                    });
            });
        }

        return $query->get();
    }
}
