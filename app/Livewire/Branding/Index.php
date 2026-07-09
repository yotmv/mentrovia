<?php

namespace App\Livewire\Branding;

use App\Ai\Text\Exceptions\TextGenerationRoleException;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use App\Services\Branding\BrandKitGenerationException;
use App\Services\Branding\BrandKitGenerator;
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

    /**
     * Active kit tab when the Flux Pro tabbed layout is rendered.
     */
    public string $tab = 'identity';

    public function generate(BrandKitGenerator $generator): void
    {
        $user = Auth::user();
        $business = $this->business();

        if (! $user instanceof User || ! $business instanceof Business) {
            return;
        }

        $this->generationError = null;

        try {
            $kit = $generator->generate($user, $business);
        } catch (BrandKitGenerationException|TextGenerationRoleException) {
            $this->generationError = __('Brand kit generation did not return usable results. Nothing was saved. Try again in a moment.');

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

        $this->generationError = null;

        try {
            $generator->regenerateSection($kit, $section);
        } catch (BrandKitGenerationException|TextGenerationRoleException) {
            $this->generationError = __('That section could not be regenerated. The rest of the kit is unchanged. Try again in a moment.');

            return;
        }

        unset($this->kits, $this->kit);

        Flux::toast(__('Section regenerated.'), variant: 'success');
    }

    public function selectPreference(string $type, int $index): void
    {
        $kit = $this->kit();

        if (! $kit instanceof BrandKit) {
            return;
        }

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

        $kit->update(['preferences' => $preferences === [] ? null : $preferences]);

        unset($this->kits, $this->kit);
    }

    #[Computed]
    public function business(): ?Business
    {
        $user = Auth::user();

        return $user instanceof User ? $user->business()->first() : null;
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
            ->where('user_id', Auth::id())
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

    public function render(): View
    {
        return view('livewire.branding.index');
    }
}
