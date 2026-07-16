<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_kits', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_revision')->nullable()->after('version');
            $table->char('profile_fingerprint', 64)->nullable()->after('profile_revision');
            $table->json('section_profile_revisions')->nullable()->after('profile_fingerprint');
            $table->json('marketing_context_fingerprints')->nullable()->after('section_profile_revisions');
        });

        Schema::table('advertising_kits', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_revision')->nullable()->after('version');
            $table->char('profile_fingerprint', 64)->nullable()->after('profile_revision');
            $table->char('brand_content_fingerprint', 64)->nullable()->after('profile_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advertising_kits', function (Blueprint $table) {
            $table->dropColumn(['profile_revision', 'profile_fingerprint', 'brand_content_fingerprint']);
        });

        Schema::table('brand_kits', function (Blueprint $table) {
            $table->dropColumn([
                'profile_revision',
                'profile_fingerprint',
                'section_profile_revisions',
                'marketing_context_fingerprints',
            ]);
        });
    }
};
