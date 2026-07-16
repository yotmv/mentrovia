<?php

namespace App\Livewire\Projects;

use App\Enums\AccountCapability;
use App\Models\Project;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public string $name = '';

    public string $projectDate = '';

    public string $photoBrief = '';

    protected CurrentAccount $currentAccount;

    public function boot(CurrentAccount $currentAccount): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $this->currentAccount = $currentAccount;
        $this->currentAccount->resolve($user);
    }

    public function mount(): void
    {
        $this->projectDate = now()->format('Y-m-d');

        $brief = request()->query('photo_brief');

        if (is_string($brief) && mb_strlen($brief) <= 2000) {
            $this->photoBrief = $brief;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createProject(AccountMutationGate $accountMutationGate): void
    {
        $this->authorize('create', Project::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'projectDate' => ['required', 'date'],
            'photoBrief' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $account = $this->currentAccount->account();
        $project = DB::transaction(function () use ($accountMutationGate, $account, $user, $validated): Project {
            $lockedAccount = $accountMutationGate->lockMemberOrFail($account->id, $user->id, AccountCapability::Project);

            return $lockedAccount->projects()->create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'project_date' => $validated['projectDate'],
            ]);
        }, attempts: 3);

        $this->redirectRoute('projects.show', [
            'project' => $project,
            'photo_brief' => $validated['photoBrief'] ?? null,
        ], navigate: true);
    }

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return Project::query()
            ->accessibleTo(Auth::user(), $this->currentAccount->account())
            ->search($this->search)
            ->withCount(['photos'])
            ->with('owner')
            ->orderByDesc('project_date')
            ->orderByDesc('id')
            ->paginate(12);
    }

    public function render(): View
    {
        // A class named Index auto-resolves to livewire/projects.blade.php;
        // point it at the conventional nested view instead.
        return view('livewire.projects.index');
    }
}
