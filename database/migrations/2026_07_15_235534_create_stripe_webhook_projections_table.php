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
        Schema::create('stripe_webhook_projections', function (Blueprint $table) {
            $table->id();
            $eventId = $table->string('stripe_event_id')->unique();

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $eventId->collation('utf8mb4_bin');
            }
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('subscription_status', 32)->nullable();
            $table->unsignedBigInteger('stripe_created_at');
            $table->string('outcome', 40);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(
                ['account_id', 'stripe_created_at', 'stripe_event_id'],
                'stripe_webhook_account_watermark_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_projections');
    }
};
