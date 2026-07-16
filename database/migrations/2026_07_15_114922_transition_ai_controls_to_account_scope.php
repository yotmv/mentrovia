<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->makeLegacyUserAttributionNullable('ai_account_settings', 'ai_account_settings_user_id_unique');
        $this->makeLegacyUserAttributionNullable('ai_provider_credentials', 'ai_provider_credentials_user_id_provider_unique');
        $this->makeLegacyUserAttributionNullable('ai_model_preferences', 'ai_model_preferences_user_id_purpose_unique');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertLegacyUserScopeCanBeRestored();

        $this->restoreLegacyUserScope('ai_model_preferences', ['user_id', 'purpose']);
        $this->restoreLegacyUserScope('ai_provider_credentials', ['user_id', 'provider']);
        $this->restoreLegacyUserScope('ai_account_settings', ['user_id']);
    }

    private function makeLegacyUserAttributionNullable(string $tableName, string $legacyUniqueIndex): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($legacyUniqueIndex): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique($legacyUniqueIndex);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function assertLegacyUserScopeCanBeRestored(): void
    {
        $problems = [];

        foreach (['ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
            $nullIds = DB::table($tableName)
                ->whereNull('user_id')
                ->orderBy('id')
                ->limit(20)
                ->pluck('id')
                ->map(fn (int|string $id): string => (string) $id)
                ->all();

            if ($nullIds !== []) {
                $problems[] = $tableName.' null user attribution ['.implode(',', $nullIds).']';
            }
        }

        foreach ([
            'ai_account_settings' => ['user_id'],
            'ai_provider_credentials' => ['user_id', 'provider'],
            'ai_model_preferences' => ['user_id', 'purpose'],
        ] as $tableName => $legacyKey) {
            $duplicates = DB::table($tableName)
                ->select($legacyKey)
                ->whereNotNull('user_id')
                ->groupBy($legacyKey)
                ->havingRaw('COUNT(*) > 1')
                ->orderBy($legacyKey[0]);

            foreach (array_slice($legacyKey, 1) as $column) {
                $duplicates->orderBy($column);
            }

            $duplicateKeys = $duplicates
                ->limit(20)
                ->get()
                ->map(fn (object $row): string => collect((array) $row)->implode(':'))
                ->all();

            if ($duplicateKeys !== []) {
                $problems[] = $tableName.' duplicate legacy keys ['.implode(',', $duplicateKeys).']';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('Cannot restore user-scoped AI controls: '.implode('; ', $problems));
        }
    }

    /** @param array<int, string> $legacyUniqueColumns */
    private function restoreLegacyUserScope(string $tableName, array $legacyUniqueColumns): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($legacyUniqueColumns): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique($legacyUniqueColumns);
        });
    }
};
