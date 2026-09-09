<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('bucket')->default('library')->after('uuid');
            $table->string('object_key')->nullable()->after('bucket');
        });

        DB::table('books')->select(['id', 'file_path'])->orderBy('id')->each(function ($book): void {
            DB::table('books')->where('id', $book->id)->update([
                'uuid' => (string) Str::uuid(),
                'object_key' => $book->file_path,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'bucket', 'object_key']);
        });
    }
};
