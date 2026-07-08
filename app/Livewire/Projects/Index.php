<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    public function mount(): void
    {
        $this->projectDate = now()->format('Y-m-d');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createProject(): void
    {
        $this->authorize('create', Project::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'projectDate' => ['required', 'date'],
        ]);

        $project = Auth::user()->projects()->create([
            'name' => $validated['name'],
            'project_date' => $validated['projectDate'],
        ]);

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return Project::query()
            ->accessibleTo(Auth::user())
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
