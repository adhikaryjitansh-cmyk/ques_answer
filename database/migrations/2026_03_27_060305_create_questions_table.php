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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('chapter')->index()->nullable();
            $table->text('question');
            $table->text('answer');
            $table->string('language', 10)->nullable(); // en/bn/mixed
            $table->boolean('has_image')->default(false);
            $table->string('image_path')->nullable();
            $table->timestamps();

            // Full-text index for search
            $table->fullText('question');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
