<?php

namespace App\Livewire\Advisor;

use App\Models\AgentConversationMessage;
use App\Models\Business;
use App\Models\User;
use App\Services\Advisor\AdvisorAnswerService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Ask extends Component
{
    public string $question = '';

    public ?string $conversationId = null;

    public function mount(AdvisorAnswerService $advisor): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $conversation = $advisor->conversationFor($user);
        $this->conversationId = $conversation->id;
    }

    public function ask(AdvisorAnswerService $advisor): void
    {
        $validated = $this->validate([
            'question' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        $user = Auth::user();
        $business = $this->business();

        if (! $user instanceof User || ! $business instanceof Business) {
            return;
        }

        $message = $advisor->answer($user, $business, $validated['question']);
        $this->conversationId = $message->conversation_id;
        $this->question = '';

        Flux::toast(__('Advisor answer ready.'), variant: 'success');
    }

    #[Computed]
    public function business(): ?Business
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->business()->with('profileAnswers')->first();
    }

    /**
     * @return EloquentCollection<int, AgentConversationMessage>
     */
    #[Computed]
    public function conversationMessages(): EloquentCollection
    {
        if ($this->conversationId === null) {
            return new EloquentCollection;
        }

        return AgentConversationMessage::query()
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', Auth::id())
            ->where('agent', 'advisor')
            ->orderBy('created_at')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.advisor.ask');
    }
}
