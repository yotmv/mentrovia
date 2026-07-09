<?php

namespace App\Livewire\Advisor;

use App\Models\AgentConversationMessage;
use App\Models\Business;
use App\Models\User;
use App\Services\Advisor\AdvisorAnswerService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Ask extends Component
{
    private const MaxQuestionsPerHour = 6;

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
        $this->question = trim($this->question);

        $validated = $this->validate([
            'question' => ['required', 'string', 'regex:/\S/', 'min:8', 'max:1000'],
        ]);

        $user = Auth::user();
        $business = $this->business();

        if (! $user instanceof User || ! $business instanceof Business) {
            return;
        }

        if (RateLimiter::tooManyAttempts($this->rateLimitKey($user), self::MaxQuestionsPerHour)) {
            $seconds = RateLimiter::availableIn($this->rateLimitKey($user));

            $this->addError('question', __('Advisor is cooling down for this account. Try again in :minutes minutes.', [
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]));

            return;
        }

        RateLimiter::increment($this->rateLimitKey($user), decaySeconds: 3600);

        $message = $advisor->answer($user, $business, $validated['question']);
        $this->conversationId = $message->conversation_id;
        $this->question = '';

        Flux::toast(__('Advisor answer ready.'), variant: 'success');
    }

    public function reportAnswer(string $messageId): void
    {
        $message = AgentConversationMessage::query()
            ->whereKey($messageId)
            ->where('user_id', Auth::id())
            ->where('agent', 'advisor')
            ->where('role', 'assistant')
            ->first();

        if (! $message instanceof AgentConversationMessage) {
            return;
        }

        $meta = $message->meta ?? [];
        $meta['feedback'] = [
            'reported' => true,
            'reported_at' => now()->toISOString(),
        ];

        $message->forceFill(['meta' => $meta])->save();

        unset($this->conversationMessages);

        Flux::toast(__('Thanks. This answer was flagged for review.'), variant: 'success');
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

    public function remainingQuestions(): int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return 0;
        }

        return RateLimiter::remaining($this->rateLimitKey($user), self::MaxQuestionsPerHour);
    }

    protected function rateLimitKey(User $user): string
    {
        return 'advisor-answer:'.$user->id;
    }

    public function render(): View
    {
        return view('livewire.advisor.ask');
    }
}
