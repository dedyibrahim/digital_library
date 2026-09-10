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
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::table('books', function (Blueprint $table): void {
            $table->foreignId('desktop_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('sync_root')->nullable();
            $table->text('sync_path')->nullable();
            $table->string('sync_path_hash', 64)->nullable();
            $table->string('sync_hash', 64)->nullable();
            $table->unique(['desktop_user_id', 'sync_root', 'sync_path_hash'], 'desktop_sync_identity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropUnique('desktop_sync_identity');
            $table->dropConstrainedForeignId('desktop_user_id');
            $table->dropColumn(['sync_root', 'sync_path', 'sync_path_hash', 'sync_hash']);
        });
        Schema::dropIfExists('personal_access_tokens');
    }
};
