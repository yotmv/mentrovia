<?php

namespace App\Livewire\Onboarding;

use App\Actions\Business\DeleteOnboardingDraft;
use App\Actions\Business\SaveOnboardingDraft;
use App\Enums\BusinessOnboardingTrack;
use App\Models\Account;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Welcome extends Component
{
    private CurrentAccount $currentAccount;

    public function boot(CurrentAccount $currentAccount): void
    {
        $this->currentAccount = $currentAccount;
        $this->currentAccount->resolve($this->user());
    }

    public function start(string $track, SaveOnboardingDraft $saveDraft): void
    {
        $selectedTrack = BusinessOnboardingTrack::tryFrom($track);
        abort_unless($selectedTrack instanceof BusinessOnboardingTrack, 404);

        $saveDraft->handle(
            $this->account(),
            $this->user(),
            $selectedTrack,
            1,
            [],
            null,
        );

        $this->redirectRoute('business.intake', navigate: true);
    }

    public function startOver(int $revision, DeleteOnboardingDraft $deleteDraft): void
    {
        $deleteDraft->handle($this->account(), $this->user(), $revision);
        unset($this->draft);
        Flux::modal('confirm-onboarding-start-over')->close();
        Flux::toast(variant: 'success', text: __('Saved onboarding progress removed.'));
    }

    #[Computed]
    public function draft(): ?OnboardingDraft
    {
        return $this->account()->onboardingDraft()->first();
    }

    public function render(): View
    {
        return view('livewire.onboarding.welcome');
    }

    private function account(): Account
    {
        return $this->currentAccount->account();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
