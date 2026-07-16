<?php

namespace App\Livewire\Advertising;

use App\Enums\ProfileFreshness;
use App\Models\AdvertisingKit;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\Advertising\AdvertisingKitGenerator;
use App\Services\ProfileFreshnessService;
use App\Support\Ai\AiFailurePresentation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    public ?int $selectedKitId = null;

    public ?string $generationError = null;

    public bool $generationErrorShowsSettings = false;

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

    public function generate(AdvertisingKitGenerator $generator): void
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

        Flux::toast(__('Advertising kit version :version is ready.', ['version' => $kit->version]), variant: 'success');
    }

    #[Computed]
    public function business(): ?Business
    {
        $user = Auth::user();

        return $user instanceof User ? $this->currentAccount->account()->business : null;
    }

    /**
     * The latest brand kit that will ground the next generation, if one exists.
     */
    #[Computed]
    public function brandKit(): ?BrandKit
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return null;
        }

        return $business->brandKits()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * All advertising kit versions for the user's business, newest first.
     *
     * @return EloquentCollection<int, AdvertisingKit>
     */
    #[Computed]
    public function kits(): EloquentCollection
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return new EloquentCollection;
        }

        return $business->advertisingKits()
            ->with('brandKit')
            ->orderByDesc('version')
            ->get();
    }

    #[Computed]
    public function kit(): ?AdvertisingKit
    {
        if ($this->selectedKitId !== null) {
            $selected = $this->kits()->firstWhere('id', $this->selectedKitId);

            if ($selected instanceof AdvertisingKit) {
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

        return $kit instanceof AdvertisingKit && $business instanceof Business
            ? $this->profileFreshness->advertising($kit, $business, $this->brandKit())
            : null;
    }

    public function render(): View
    {
        return view('livewire.advertising.index');
    }
}
