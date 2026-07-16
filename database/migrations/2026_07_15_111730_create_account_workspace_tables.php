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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('account_user', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->unsignedBigInteger('owner_account_id')
                    ->storedAs("CASE WHEN role = 'owner' THEN account_id ELSE NULL END");
            }
            $table->timestamps();
            $table->primary(['account_id', 'user_id']);
            $table->index(['user_id', 'account_id']);
            $table->index(['account_id', 'role']);

            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->unique('owner_account_id', 'account_user_one_owner_unique');
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX account_user_one_owner_unique ON account_user (account_id) WHERE role = 'owner'");
        }

        Schema::create('account_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan', 40)->default('beta');
            $table->string('status', 20)->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_entitlements');
        Schema::dropIfExists('account_user');
        Schema::dropIfExists('accounts');
    }
};
