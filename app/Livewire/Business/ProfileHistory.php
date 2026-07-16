<?php

namespace App\Livewire\Business;

use App\Models\Business;
use App\Models\BusinessProfileVersion;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BusinessProfileValuePresenter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class ProfileHistory extends Component
{
    use WithPagination;

    protected CurrentAccount $currentAccount;

    protected BusinessProfileValuePresenter $valuePresenter;

    public function boot(CurrentAccount $currentAccount, BusinessProfileValuePresenter $valuePresenter): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        $this->currentAccount = $currentAccount;
        $this->valuePresenter = $valuePresenter;
        $this->currentAccount->resolve($user);
    }

    public function mount(): void
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            $this->redirectRoute('onboarding.welcome', navigate: true);

            return;
        }

        $this->authorize('view', $business);
    }

    #[Computed]
    public function business(): ?Business
    {
        return $this->currentAccount->account()->business;
    }

    /** @return LengthAwarePaginator<int, BusinessProfileVersion> */
    #[Computed]
    public function versions(): LengthAwarePaginator
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return new LengthAwarePaginator([], 0, 10);
        }

        return $business->profileVersions()
            ->with('creator:id,name')
            ->orderByDesc('revision')
            ->paginate(10, pageName: 'profile-history-page');
    }

    /** @return list<array{version: BusinessProfileVersion, changes: list<array{field: string, before: mixed, after: mixed}>}> */
    #[Computed]
    public function timeline(): array
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return [];
        }

        $versions = $this->versions()->getCollection();
        $oldest = $versions->last();
        $oldestRevision = $oldest instanceof BusinessProfileVersion ? $oldest->revision : 0;
        $adjacentOlder = $oldestRevision > 1
            ? $business->profileVersions()->where('revision', '<', $oldestRevision)->orderByDesc('revision')->first()
            : null;

        $timeline = [];

        foreach ($versions as $index => $version) {
            $previous = $versions->get($index + 1) ?? ($index === $versions->count() - 1 ? $adjacentOlder : null);
            $changes = [];

            foreach ($version->changed_field_keys as $field) {
                $changes[] = [
                    'field' => $field,
                    'before' => $previous instanceof BusinessProfileVersion ? $this->snapshotValue($previous->snapshot, $field) : null,
                    'after' => $this->snapshotValue($version->snapshot, $field),
                ];
            }

            $timeline[] = ['version' => $version, 'changes' => $changes];
        }

        return $timeline;
    }

    public function render(): View
    {
        return view('livewire.business.profile-history');
    }

    public function fieldLabel(string $field): string
    {
        return str($field)
            ->after('profile_answers.')
            ->after('banking_setup.')
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();
    }

    public function displayValue(string $field, mixed $value): string
    {
        return $this->valuePresenter->present($field, $value);
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotValue(array $snapshot, string $field): mixed
    {
        if (! str_starts_with($field, 'profile_answers.')) {
            return $snapshot['business'][$field] ?? null;
        }

        $questionKey = substr($field, strlen('profile_answers.'));
        $profileAnswers = $snapshot['profile_answers'] ?? [];

        if (! is_array($profileAnswers)) {
            return null;
        }

        $answer = null;

        foreach ($profileAnswers as $candidate) {
            if (is_array($candidate) && ($candidate['question_key'] ?? null) === $questionKey) {
                $answer = $candidate;

                break;
            }
        }

        if ($answer === null) {
            return null;
        }

        $value = $answer['answer_value'] ?? null;
        $confidence = $answer['confidence'] ?? null;

        return is_string($confidence) && $confidence !== ''
            ? (string) $value.' · '.str($confidence)->replace('_', ' ')->title()
            : $value;
    }
}
