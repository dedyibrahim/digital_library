<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('title');
            $table->string('author');
            $table->string('isbn', 20)->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('published_year')->nullable();
            $table->string('cover_url')->nullable();
            $table->unsignedInteger('total_copies')->default(1);
            $table->unsignedInteger('available_copies')->default(1);
            $table->timestamps();

            $table->index(['category_id', 'title']);
            $table->index('author');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
        Schema::dropIfExists('categories');
    }
};
