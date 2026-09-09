<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_change_library_data(): void
    {
        $this->post(route('categories.store'), ['name' => 'Rahasia'])
            ->assertRedirect(route('login'));
    }

    public function test_user_can_create_a_folder(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('categories.store'), ['name' => 'Referensi'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', ['name' => 'Referensi']);
    }

    public function test_user_can_upload_star_download_and_delete_a_book(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->post(route('books.store'), [
            'category_id' => $category->id,
            'files' => [UploadedFile::fake()->create('panduan.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('catalog.index'));

        $book = Book::where('original_filename', 'panduan.pdf')->firstOrFail();
        Storage::disk('local')->assertExists($book->object_key);
        $this->assertStringStartsWith('objects/library/', $book->object_key);

        $this->patch(route('books.star', $book))->assertRedirect();
        $this->assertTrue($book->fresh()->is_starred);

        $this->get(route('books.content', $book))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertNull($book->fresh()->last_opened_at);

        $this->get(route('books.download', $book))->assertOk()->assertDownload('panduan.pdf');
        $this->get(route('books.open', $book))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertNotNull($book->fresh()->last_opened_at);

        $path = $book->object_key;
        $this->delete(route('books.destroy', $book))->assertRedirect();
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_user_can_upload_multiple_file_types_without_metadata_form(): void
    {
        Storage::fake('local');
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create())->post(route('books.store'), [
            'category_id' => $category->id,
            'files' => [
                UploadedFile::fake()->create('foto.jpg', 10, 'image/jpeg'),
                UploadedFile::fake()->create('arsip.zip', 10, 'application/zip'),
                UploadedFile::fake()->create('data.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ],
        ])->assertRedirect(route('catalog.index'));

        $this->assertDatabaseCount('books', 3);
        $this->assertDatabaseHas('books', ['original_filename' => 'foto.jpg']);
        $this->assertDatabaseHas('books', ['original_filename' => 'arsip.zip']);
        $this->assertDatabaseHas('books', ['original_filename' => 'data.xlsx']);
    }

    public function test_previous_upload_url_remains_compatible_with_open_browser_tabs(): void
    {
        Storage::fake('local');
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create())->post('/books', [
            'category_id' => $category->id,
            'files' => [UploadedFile::fake()->create('kompatibel.txt', 1, 'text/plain')],
        ])->assertRedirect(route('catalog.index'));

        $this->assertDatabaseHas('books', ['original_filename' => 'kompatibel.txt']);
    }
}
