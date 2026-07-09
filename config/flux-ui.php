<?php

use Composer\InstalledVersions;

return [

    /*
    |--------------------------------------------------------------------------
    | Flux UI Kit
    |--------------------------------------------------------------------------
    |
    | Mentrovia is open source and must render correctly for installs that
    | only have the free edition of Flux UI. Views that can take advantage
    | of Pro components check this value and fall back to free markup.
    |
    | Supported values: "flux-free", "flux-pro". Defaults to "flux-free"
    | unless a licensed livewire/flux-pro install is detected. Set
    | FLUX_UI_KIT to pin the kit explicitly in either direction.
    |
    */

    'kit' => env('FLUX_UI_KIT') ?? (InstalledVersions::isInstalled('livewire/flux-pro') ? 'flux-pro' : 'flux-free'),

];
