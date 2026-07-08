<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_generation_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('disk');
            $table->string('path', 1024);
            $table->json('derivatives')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('processing_status')->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('original_filename')->nullable();
            $table->text('text')->nullable();
            $table->string('text_source')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('mode')->nullable();
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->string('cost_source')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
