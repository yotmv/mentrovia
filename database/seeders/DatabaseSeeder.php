<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(KnowledgeArticleSeeder::class);
        $this->call(RecurringTaskTemplateSeeder::class);

        /* for local development only */
        User::firstOrCreate(
            ['email' => 'brian@kgtech.co'],
            [
                'name' => 'Brian',
                'password' => Hash::make('secret'),
                'email_verified_at' => now(),
            ]
        );
    }
}
