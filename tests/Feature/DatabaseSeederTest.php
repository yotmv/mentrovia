<?php

use App\Models\KnowledgeArticle;
use App\Models\RecurringTaskTemplate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('production seeding installs reference data without creating a login account', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--force' => true,
    ])->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and(KnowledgeArticle::query()->count())->toBeGreaterThan(0)
        ->and(RecurringTaskTemplate::query()->count())->toBeGreaterThan(0);
});
