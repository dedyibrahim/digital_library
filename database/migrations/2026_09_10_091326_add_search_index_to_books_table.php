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
        Schema::table('books', function (Blueprint $table) {
            $table->longText('search_text')->nullable();
            $table->string('search_status', 20)->default('pending')->index();
            $table->uuid('search_version')->nullable();
            $table->timestamp('search_indexed_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX books_content_search_idx ON books USING GIN (to_tsvector('simple', coalesce(search_text, '')))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['search_text', 'search_status', 'search_version', 'search_indexed_at']);
        });
    }
};
