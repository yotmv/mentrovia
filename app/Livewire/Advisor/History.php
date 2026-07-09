<?php

namespace App\Livewire\Advisor;

use App\Models\AgentConversationMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class History extends Component
{
    /**
     * @return EloquentCollection<int, AgentConversationMessage>
     */
    #[Computed]
    public function messages(): EloquentCollection
    {
        return AgentConversationMessage::query()
            ->where('user_id', Auth::id())
            ->where('agent', 'advisor')
            ->where('role', 'assistant')
            ->latest()
            ->limit(50)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.advisor.history');
    }
}
