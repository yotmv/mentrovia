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
        Schema::table('accounts', function (Blueprint $table) {
            $stripeId = $table->string('stripe_id')->nullable()->index();

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $stripeId->collation('utf8mb4_bin');
            }
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->uuid('billing_checkout_token')->nullable();
            $checkoutSessionId = $table->string('billing_checkout_session_id')->nullable();

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $checkoutSessionId->collation('utf8mb4_bin');
            }
            $table->timestamp('billing_checkout_expires_at')->nullable()->index();
            $table->string('billing_checkout_status', 16)->nullable();
            $table->string('billing_checkout_interval', 16)->nullable();
            $table->char('billing_checkout_price_fingerprint', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropIndex(['billing_checkout_expires_at']);
            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
                'billing_checkout_token',
                'billing_checkout_session_id',
                'billing_checkout_expires_at',
                'billing_checkout_status',
                'billing_checkout_interval',
                'billing_checkout_price_fingerprint',
            ]);
        });
    }
};
