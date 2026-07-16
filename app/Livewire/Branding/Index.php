<?php

namespace App\Livewire\Branding;

use App\Enums\AccountCapability;
use App\Enums\ProfileFreshness;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use App\Services\Branding\BrandKitGenerator;
use App\Services\ProfileFreshnessService;
use App\Support\Ai\AiFailurePresentation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    public ?int $selectedKitId = null;

    public ?string $generationError = null;

    public bool $generationErrorShowsSettings = false;

    /**
     * Active kit tab when the Flux Pro tabbed layout is rendered.
     */
    public string $tab = 'identity';

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

    public function generate(BrandKitGenerator $generator): void
    {
        $user = Auth::user();
        $business = $this->business();

        if (! $user instanceof User || ! $business instanceof Business) {
            return;
        }

        $this->authorize('operate', $business);

        $this->generationError = null;
        $this->generationErrorShowsSettings = false;

        try {
            $kit = $generator->generate($user, $business);
        } catch (Throwable $exception) {
            $failure = AiFailurePresentation::fromException($exception);
            $this->generationError = $failure->message;
            $this->generationErrorShowsSettings = $failure->showsSettingsAction;

            return;
        }

        $this->selectedKitId = $kit->id;
        unset($this->kits, $this->kit);

        Flux::toast(__('Brand kit version :version is ready.', ['version' => $kit->version]), variant: 'success');
    }

    public function regenerateSection(string $section, BrandKitGenerator $generator): void
    {
        $kit = $this->kit();

        if (! $kit instanceof BrandKit || ! array_key_exists($section, BrandKitGenerator::Sections)) {
            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        $this->authorize('operate', $kit->business);

        $this->generationError = null;
        $this->generationErrorShowsSettings = false;

        try {
            $generator->regenerateSection($kit, $section, $user);
        } catch (Throwable $exception) {
            $failure = AiFailurePresentation::fromException($exception);
            $this->generationError = $failure->message;
            $this->generationErrorShowsSettings = $failure->showsSettingsAction;

            return;
        }

        unset($this->kits, $this->kit);

        Flux::toast(__('Section regenerated.'), variant: 'success');
    }

    public function selectPreference(string $type, int $index, AccountMutationGate $accountMutationGate): void
    {
        $kit = $this->kit();

        if (! $kit instanceof BrandKit) {
            return;
        }

        $this->authorize('operate', $kit->business);

        $value = match ($type) {
            'name' => $kit->name_ideas[$index] ?? null,
            'tagline' => $kit->tagline_options[$index] ?? null,
            'color' => $kit->color_palette[$index]['hex'] ?? null,
            default => null,
        };

        if ($value === null) {
            return;
        }

        $preferences = $kit->preferences ?? [];

        if (($preferences[$type] ?? null) === $value) {
            unset($preferences[$type]);
        } else {
            $preferences[$type] = $value;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($kit, $preferences, $accountMutationGate, $user): void {
            $accountMutationGate->lockMemberOrFail($kit->business->account_id, $user->id, AccountCapability::Workspace);
            BrandKit::query()->lockForUpdate()->findOrFail($kit->id)->update([
                'preferences' => $preferences === [] ? null : $preferences,
            ]);
        }, attempts: 3);

        unset($this->kits, $this->kit);
    }

    #[Computed]
    public function business(): ?Business
    {
        $user = Auth::user();

        return $user instanceof User ? $this->currentAccount->account()->business : null;
    }

    /**
     * All brand kit versions for the user's business, newest first.
     *
     * @return EloquentCollection<int, BrandKit>
     */
    #[Computed]
    public function kits(): EloquentCollection
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return new EloquentCollection;
        }

        return $business->brandKits()
            ->orderByDesc('version')
            ->get();
    }

    #[Computed]
    public function kit(): ?BrandKit
    {
        if ($this->selectedKitId !== null) {
            $selected = $this->kits()->firstWhere('id', $this->selectedKitId);

            if ($selected instanceof BrandKit) {
                return $selected;
            }
        }

        return $this->kits()->first();
    }

    #[Computed]
    public function kitFreshness(): ?ProfileFreshness
    {
        $kit = $this->kit();
        $business = $this->business();

        return $kit instanceof BrandKit && $business instanceof Business
            ? $this->profileFreshness->brand($kit, $business)
            : null;
    }

    public function sectionFreshness(string $section): ProfileFreshness
    {
        $kit = $this->kit();
        $business = $this->business();

        return $kit instanceof BrandKit && $business instanceof Business
            ? $this->profileFreshness->brandSection($kit, $business, $section)
            : ProfileFreshness::Unknown;
    }

    public function render(): View
    {
        return view('livewire.branding.index');
    }
}
