<?php

namespace App\Livewire\Advertising;

use App\Ai\Text\Exceptions\TextGenerationRoleException;
use App\Models\AdvertisingKit;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use App\Services\Advertising\AdvertisingKitGenerationException;
use App\Services\Advertising\AdvertisingKitGenerator;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Index extends Component
{
    public ?int $selectedKitId = null;

    public ?string $generationError = null;

    public function generate(AdvertisingKitGenerator $generator): void
    {
        $user = Auth::user();
        $business = $this->business();

        if (! $user instanceof User || ! $business instanceof Business) {
            return;
        }

        $this->generationError = null;

        try {
            $kit = $generator->generate($user, $business);
        } catch (AdvertisingKitGenerationException|TextGenerationRoleException) {
            $this->generationError = __('Advertising generation did not return usable results. Nothing was saved. Try again in a moment.');

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

        return $user instanceof User ? $user->business()->first() : null;
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
            ->where('user_id', Auth::id())
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
            ->where('user_id', Auth::id())
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

    public function render(): View
    {
        return view('livewire.advertising.index');
    }
}
