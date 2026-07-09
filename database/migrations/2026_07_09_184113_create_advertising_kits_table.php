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
        Schema::create('advertising_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->json('ad_angles');
            $table->json('facebook_instagram_copy');
            $table->json('google_ads');
            $table->json('social_posts');
            $table->json('flyer_copy')->nullable();
            $table->json('image_prompts');
            $table->json('landing_page_outline');
            $table->json('thirty_day_plan');
            $table->string('provider');
            $table->string('model');
            $table->string('config_version');
            $table->json('raw_response')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'version']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advertising_kits');
    }
};
