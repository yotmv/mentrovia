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
        $conversationTable = (string) config('ai.conversations.tables.conversations', 'agent_conversations');
        $messageTable = (string) config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $problems = [];

        $nullAccountConversationIds = DB::table($conversationTable)
            ->whereNull('account_id')
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->all();

        if ($nullAccountConversationIds !== []) {
            $problems[] = 'conversations without account scope ['.implode(',', $nullAccountConversationIds).']';
        }

        $orphanMessageIds = DB::table($messageTable)
            ->leftJoin($conversationTable, $conversationTable.'.id', '=', $messageTable.'.conversation_id')
            ->whereNull($conversationTable.'.id')
            ->orderBy($messageTable.'.id')
            ->limit(20)
            ->pluck($messageTable.'.id')
            ->all();

        if ($orphanMessageIds !== []) {
            $problems[] = 'orphan conversation messages ['.implode(',', $orphanMessageIds).']';
        }

        if ($problems !== []) {
            throw new RuntimeException('Account conversation integrity preflight failed: '.implode('; ', $problems));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
