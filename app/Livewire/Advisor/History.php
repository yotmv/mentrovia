<?php

namespace App\Livewire\Advisor;

use App\Enums\ProfileFreshness;
use App\Models\AgentConversationMessage;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\ProfileFreshnessService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class History extends Component
{
    protected CurrentAccount $currentAccount;

    protected ProfileFreshnessService $profileFreshness;

    public function boot(CurrentAccount $currentAccount, ProfileFreshnessService $profileFreshness): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $this->currentAccount = $currentAccount;
        $this->profileFreshness = $profileFreshness;
        $this->currentAccount->resolve($user);
    }

    /**
     * @return EloquentCollection<int, AgentConversationMessage>
     */
    #[Computed]
    public function messages(): EloquentCollection
    {
        return AgentConversationMessage::query()
            ->whereHas('conversation', fn ($query) => $query->where('account_id', $this->currentAccount->id()))
            ->where('agent', 'advisor')
            ->where('role', 'assistant')
            ->latest()
            ->limit(50)
            ->get();
    }

    public function messageFreshness(AgentConversationMessage $message): ProfileFreshness
    {
        $business = $this->currentAccount->account()->business;

        return $business instanceof Business
            ? $this->profileFreshness->advisor($message, $business)
            : ProfileFreshness::Unknown;
    }

    public function askAgain(string $messageId): void
    {
        $message = AgentConversationMessage::query()
            ->whereKey($messageId)
            ->whereHas('conversation', fn ($query) => $query->where('account_id', $this->currentAccount->id()))
            ->where('agent', 'advisor')
            ->where('role', 'assistant')
            ->first();
        $question = data_get($message?->meta, 'question');

        if (! is_string($question) || blank($question) || mb_strlen($question) > 1000) {
            return;
        }

        session()->flash('advisor_repeat_message_id', $message->id);
        $this->redirectRoute('advisor', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.advisor.history');
    }
}
