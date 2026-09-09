<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->string('file_path')->nullable()->after('cover_url');
            $table->string('original_filename')->nullable()->after('file_path');
            $table->string('mime_type')->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->boolean('is_starred')->default(false)->after('file_size');
            $table->timestamp('last_opened_at')->nullable()->after('is_starred');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropColumn(['file_path', 'original_filename', 'mime_type', 'file_size', 'is_starred', 'last_opened_at']);
        });
    }
};
