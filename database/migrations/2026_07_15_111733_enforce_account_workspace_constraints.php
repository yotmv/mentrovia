<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $problems = collect();

        $this->capture($problems, 'invalid current memberships', DB::table('users')
            ->leftJoin('accounts', 'accounts.id', '=', 'users.current_account_id')
            ->leftJoin('account_user', function ($join): void {
                $join->on('account_user.account_id', '=', 'users.current_account_id')
                    ->on('account_user.user_id', '=', 'users.id');
            })->where(fn ($query) => $query->whereNull('users.current_account_id')->orWhereNull('accounts.id')->orWhereNull('account_user.user_id'))
            ->select('users.id')->limit(20)->pluck('id')->all());

        $this->capture($problems, 'accounts without one owner', DB::table('accounts')
            ->leftJoin('account_user', function ($join): void {
                $join->on('account_user.account_id', '=', 'accounts.id')->where('account_user.role', '=', 'owner');
            })->groupBy('accounts.id')->havingRaw('COUNT(account_user.user_id) <> 1')->limit(20)->pluck('accounts.id')->all());

        $this->capture($problems, 'accounts without one entitlement', DB::table('accounts')
            ->leftJoin('account_entitlements', 'account_entitlements.account_id', '=', 'accounts.id')
            ->groupBy('accounts.id')->havingRaw('COUNT(account_entitlements.id) <> 1')->limit(20)->pluck('accounts.id')->all());

        foreach (['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
            $this->capture($problems, $tableName.' without valid account', DB::table($tableName)
                ->leftJoin('accounts', 'accounts.id', '=', $tableName.'.account_id')
                ->where(fn ($query) => $query->whereNull($tableName.'.account_id')->orWhereNull('accounts.id'))
                ->limit(20)->pluck($tableName.'.id')->all());
        }

        $this->captureDuplicates($problems, 'business account', 'businesses', ['account_id']);
        $this->captureDuplicates($problems, 'AI setting account', 'ai_account_settings', ['account_id']);
        $this->captureDuplicates($problems, 'AI credential account/provider', 'ai_provider_credentials', ['account_id', 'provider']);
        $this->captureDuplicates($problems, 'AI preference account/purpose', 'ai_model_preferences', ['account_id', 'purpose']);

        if ($problems->isNotEmpty()) {
            throw new RuntimeException('Account workspace preflight failed: '.$problems->implode('; '));
        }

        foreach (['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('account_id')->nullable(false)->change();
                $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            });
        }

        Schema::table('users', fn (Blueprint $table) => $table->foreign('current_account_id')->references('id')->on('accounts')->nullOnDelete());
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), fn (Blueprint $table) => $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete());
        Schema::table('businesses', fn (Blueprint $table) => $table->unique('account_id'));
        Schema::table('ai_account_settings', fn (Blueprint $table) => $table->unique('account_id'));
        Schema::table('ai_provider_credentials', fn (Blueprint $table) => $table->unique(['account_id', 'provider']));
        Schema::table('ai_model_preferences', fn (Blueprint $table) => $table->unique(['account_id', 'purpose']));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_model_preferences', fn (Blueprint $table) => $table->dropUnique(['account_id', 'purpose']));
        Schema::table('ai_provider_credentials', fn (Blueprint $table) => $table->dropUnique(['account_id', 'provider']));
        Schema::table('ai_account_settings', fn (Blueprint $table) => $table->dropUnique(['account_id']));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropUnique(['account_id']));
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), fn (Blueprint $table) => $table->dropForeign(['account_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['current_account_id']));

        foreach (['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['account_id']);
                $table->unsignedBigInteger('account_id')->nullable()->change();
            });
        }
    }

    /**
     * @param  Collection<int, string>  $problems
     * @param  array<int, int|string>  $ids
     */
    private function capture(Collection $problems, string $label, array $ids): void
    {
        if ($ids !== []) {
            $problems->push($label.' ['.implode(',', $ids).']');
        }
    }

    /**
     * @param  Collection<int, string>  $problems
     * @param  array<int, string>  $columns
     */
    private function captureDuplicates(Collection $problems, string $label, string $table, array $columns): void
    {
        $rows = DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(20)->get();

        if ($rows->isNotEmpty()) {
            $problems->push($label.' duplicates ['.$rows->map(fn ($row) => collect((array) $row)->implode(':'))->implode(',').']');
        }
    }
};
