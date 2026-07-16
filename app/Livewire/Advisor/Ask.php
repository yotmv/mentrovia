<?php

namespace App\Livewire\Advisor;

use App\Enums\AccountCapability;
use App\Enums\ProfileFreshness;
use App\Models\AgentConversationMessage;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use App\Services\Advisor\AdvisorAnswerService;
use App\Services\ProfileFreshnessService;
use App\Support\Ai\AiFailurePresentation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class Ask extends Component
{
    private const MaxQuestionsPerHour = 6;

    public string $question = '';

    public ?string $conversationId = null;

    public ?string $aiError = null;

    public bool $aiErrorShowsSettings = false;

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

    public function mount(AdvisorAnswerService $advisor): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $conversation = $advisor->conversationFor($user);
        $this->conversationId = $conversation->id;

        $repeatedMessageId = session()->pull('advisor_repeat_message_id');
        $repeatedQuestion = is_string($repeatedMessageId) ? $this->questionForMessage($repeatedMessageId) : null;

        if (is_string($repeatedQuestion) && mb_strlen($repeatedQuestion) <= 1000) {
            $this->question = $repeatedQuestion;
        }
    }

    public function ask(AdvisorAnswerService $advisor): void
    {
        $this->aiError = null;
        $this->aiErrorShowsSettings = false;
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

        $rateLimitKey = $this->rateLimitKey($user);
        RateLimiter::increment($rateLimitKey, decaySeconds: 3600);

        try {
            $message = $advisor->answer($user, $business, $validated['question']);
        } catch (Throwable $exception) {
            RateLimiter::decrement($rateLimitKey, decaySeconds: 3600);

            $failure = AiFailurePresentation::fromException($exception);
            $this->aiError = $failure->message;
            $this->aiErrorShowsSettings = $failure->showsSettingsAction;

            return;
        }

        $this->conversationId = $message->conversation_id;
        $this->question = '';

        Flux::toast(__('Advisor answer ready.'), variant: 'success');
    }

    public function chooseStarterQuestion(string $question): void
    {
        $this->question = $question;
        $this->resetErrorBag('question');
    }

    public function askAgain(string $messageId): void
    {
        $question = $this->questionForMessage($messageId);

        if (! is_string($question) || blank($question) || mb_strlen($question) > 1000) {
            return;
        }

        $this->question = $question;
        $this->resetErrorBag('question');
        $this->dispatch('advisor-focus-question');
    }

    private function questionForMessage(string $messageId): ?string
    {
        $message = AgentConversationMessage::query()
            ->whereKey($messageId)
            ->whereHas('conversation', fn ($query) => $query->where('account_id', $this->currentAccount->id()))
            ->where('agent', 'advisor')
            ->where('role', 'assistant')
            ->first();
        $question = data_get($message?->meta, 'question');

        return is_string($question) && filled($question) && mb_strlen($question) <= 1000
            ? $question
            : null;
    }

    public function reportAnswer(string $messageId, AccountMutationGate $accountMutationGate): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($messageId, $accountMutationGate, $user): void {
            $accountId = $this->currentAccount->id();
            $accountMutationGate->lockMemberOrFail($accountId, $user->id, AccountCapability::Workspace);
            $message = AgentConversationMessage::query()
                ->whereKey($messageId)
                ->whereHas('conversation', fn ($query) => $query->where('account_id', $accountId))
                ->where('agent', 'advisor')
                ->where('role', 'assistant')
                ->lockForUpdate()
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
        }, attempts: 3);

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

        return $this->currentAccount->account()->business()->with('profileAnswers')->first();
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
            ->whereHas('conversation', fn ($query) => $query->where('account_id', $this->currentAccount->id()))
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

    public function messageFreshness(AgentConversationMessage $message): ProfileFreshness
    {
        $business = $this->business();

        return $business instanceof Business
            ? $this->profileFreshness->advisor($message, $business)
            : ProfileFreshness::Unknown;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function starterQuestions(): array
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return [];
        }

        $questions = [
            __('What is the most important next step for my business this month?'),
            __('Which recurring tasks should I pay attention to first?'),
        ];

        if (! $business->has_ein->isYes()) {
            $questions[] = __('Do I need an EIN before I open a business bank account?');
        }

        if ($business->mayHaveTaxableSales() && ! $business->has_sales_tax_permit->isYes()) {
            $questions[] = __('How should I confirm whether my sales need a Texas sales-tax permit?');
        }

        if ($business->employee_count > 0 && ! $business->has_payroll) {
            $questions[] = __('What should I confirm before running payroll for my employees?');
        }

        return array_slice($questions, 0, 5);
    }

    protected function rateLimitKey(User $user): string
    {
        return 'advisor-answer:'.$this->currentAccount->id().':'.$user->id;
    }

    public function render(): View
    {
        return view('livewire.advisor.ask');
    }
}
