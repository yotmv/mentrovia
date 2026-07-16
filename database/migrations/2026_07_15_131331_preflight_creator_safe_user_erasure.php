<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $problems = [];

        $this->capture($problems, 'active account erasures', DB::table('account_erasure_progress')
            ->join('users', 'users.id', '=', 'account_erasure_progress.user_id')
            ->whereNotNull('users.account_erasure_started_at')
            ->orderBy('account_erasure_progress.id')
            ->limit(20)
            ->pluck('account_erasure_progress.id')
            ->all());
        $this->capture($problems, 'orphan conversation users', DB::table(config('ai.conversations.tables.conversations', 'agent_conversations').' as conversations')
            ->leftJoin('users', 'users.id', '=', 'conversations.user_id')
            ->whereNotNull('conversations.user_id')
            ->whereNull('users.id')
            ->limit(20)
            ->pluck('conversations.id')
            ->all());
        $this->capture($problems, 'orphan conversation messages', DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages').' as messages')
            ->leftJoin(config('ai.conversations.tables.conversations', 'agent_conversations').' as conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereNull('conversations.id')
            ->limit(20)
            ->pluck('messages.id')
            ->all());
        $this->capture($problems, 'orphan conversation message users', DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages').' as messages')
            ->leftJoin('users', 'users.id', '=', 'messages.user_id')
            ->whereNotNull('messages.user_id')
            ->whereNull('users.id')
            ->limit(20)
            ->pluck('messages.id')
            ->all());
        $this->capture($problems, 'orphan lease initiators', DB::table('photo_operation_leases')
            ->leftJoin('users', 'users.id', '=', 'photo_operation_leases.initiating_user_id')
            ->whereNull('users.id')
            ->limit(20)
            ->pluck('photo_operation_leases.id')
            ->all());
        $this->capture($problems, 'orphan lease projects', DB::table('photo_operation_leases')
            ->leftJoin('projects', 'projects.id', '=', 'photo_operation_leases.project_id')
            ->whereNull('projects.id')
            ->limit(20)
            ->pluck('photo_operation_leases.id')
            ->all());

        if ($problems !== []) {
            throw new RuntimeException('Creator-safe user erasure preflight failed: '.implode('; ', $problems));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This read-only deployment preflight has no state to reverse.
    }

    /**
     * @param  array<int, string>  $problems
     * @param  array<int, int|string>  $ids
     */
    private function capture(array &$problems, string $label, array $ids): void
    {
        if ($ids !== []) {
            $problems[] = $label.' ['.implode(',', $ids).']';
        }
    }
};
