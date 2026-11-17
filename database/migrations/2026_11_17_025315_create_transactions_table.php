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
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();
            $table->string('status')->index();
            $table->decimal('estimated_min_price', 12, 2);
            $table->decimal('estimated_max_price', 12, 2);
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->decimal('provider_cost', 18, 9)->nullable();
            $table->decimal('actual_cost', 18, 9)->nullable();
            $table->string('image_url', 4096)->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('image_stored_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
