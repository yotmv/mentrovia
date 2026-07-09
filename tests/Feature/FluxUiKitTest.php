<?php

use App\Enums\FluxUiKit;
use Composer\InstalledVersions;

test('flux ui kit auto-detects a flux pro install by default', function () {
    if (env('FLUX_UI_KIT') !== null) {
        $this->markTestSkipped('FLUX_UI_KIT is pinned in this environment.');
    }

    $expected = InstalledVersions::isInstalled('livewire/flux-pro') ? 'flux-pro' : 'flux-free';

    expect(config('flux-ui.kit'))->toBe($expected);
});

test('current kit falls back to flux-free for unknown or missing config values', function () {
    config(['flux-ui.kit' => 'not-a-kit']);
    expect(FluxUiKit::current())->toBe(FluxUiKit::FluxFree);

    config(['flux-ui.kit' => null]);
    expect(FluxUiKit::current())->toBe(FluxUiKit::FluxFree)
        ->and(FluxUiKit::current()->isPro())->toBeFalse();
});

test('current kit honors an explicitly configured kit', function () {
    config(['flux-ui.kit' => 'flux-pro']);
    expect(FluxUiKit::current())->toBe(FluxUiKit::FluxPro)
        ->and(FluxUiKit::current()->isPro())->toBeTrue();

    config(['flux-ui.kit' => 'flux-free']);
    expect(FluxUiKit::current())->toBe(FluxUiKit::FluxFree)
        ->and(FluxUiKit::current()->isPro())->toBeFalse();
});
