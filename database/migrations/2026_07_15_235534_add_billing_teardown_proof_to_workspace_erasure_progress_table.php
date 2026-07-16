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
        Schema::table('workspace_erasure_progress', function (Blueprint $table) {
            $customerId = $table->string('billing_customer_id')->nullable()->after('storage_verified_at');

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $customerId->collation('utf8mb4_bin');
            }
            $table->string('billing_teardown_proof', 64)->nullable()->after('billing_customer_id');
            $table->timestamp('billing_teardown_completed_at')->nullable()->after('billing_teardown_proof');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_erasure_progress', function (Blueprint $table) {
            $table->dropColumn([
                'billing_customer_id',
                'billing_teardown_proof',
                'billing_teardown_completed_at',
            ]);
        });
    }
};
