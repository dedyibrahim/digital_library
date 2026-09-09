<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FileActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_rename_files_or_create_share_links(): void
    {
        $book = Book::factory()->create(['title' => 'Original']);

        $this->patch(route('books.rename', $book), ['name' => 'Changed'])->assertRedirect(route('login'));
        $this->postJson(route('books.share', $book))->assertUnauthorized();

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => 'Original']);
    }

    public function test_renaming_updates_the_download_filename_without_moving_or_changing_the_file(): void
    {
        Storage::fake('local');
        Storage::put('objects/library/report.txt', 'Original file contents');
        $book = Book::factory()->create([
            'title' => 'report.txt', 'original_filename' => 'report.txt',
            'object_key' => 'objects/library/report.txt', 'mime_type' => 'text/plain',
        ]);

        $this->actingAs(User::factory()->create())->patch(route('books.rename', $book), [
            'name' => 'Laporan akhir.txt', 'object_key' => 'other-file', 'author' => 'Changed author',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('books', [
            'id' => $book->id, 'uuid' => $book->uuid, 'title' => 'Laporan akhir.txt',
            'original_filename' => 'Laporan akhir.txt', 'object_key' => 'objects/library/report.txt', 'author' => $book->author,
        ]);
        Storage::disk('local')->assertExists('objects/library/report.txt');
        $this->get(route('books.download', $book))->assertDownload('Laporan akhir.txt')->assertStreamedContent('Original file contents');
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith(['../renamed.txt'])]
    #[TestWith(['folder\\renamed.txt'])]
    #[TestWith(["invalid\nname.txt"])]
    #[TestWith(['renamed.exe'])]
    public function test_invalid_names_leave_the_original_filename_unchanged(string $name): void
    {
        $book = Book::factory()->create(['title' => 'report.txt', 'original_filename' => 'report.txt']);

        $this->actingAs(User::factory()->create())->patch(route('books.rename', $book), ['name' => $name])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => 'report.txt', 'original_filename' => 'report.txt']);
    }

    public function test_metadata_only_records_can_be_renamed_without_creating_a_filename(): void
    {
        $book = Book::factory()->create(['original_filename' => null]);

        $this->actingAs(User::factory()->create())->patch(route('books.rename', $book), ['name' => 'Katalog baru'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => 'Katalog baru', 'original_filename' => null]);
    }

    public function test_created_share_links_allow_guest_downloads_for_seven_days_only(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        Storage::put('objects/library/shared.txt', 'Shared file contents');
        $book = Book::factory()->create(['object_key' => 'objects/library/shared.txt', 'original_filename' => 'shared.txt']);

        $response = $this->actingAs(User::factory()->create())->postJson(route('books.share', $book))
            ->assertOk()->assertJsonStructure(['url', 'expires_at']);

        $url = $response->json('url');
        auth()->logout();
        $this->assertGuest();
        $this->get($url)->assertDownload('shared.txt')->assertStreamedContent('Shared file contents');
        $this->travel(7)->days();
        $this->travel(1)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_unsigned_tampered_and_other_file_share_links_are_rejected(): void
    {
        $book = Book::factory()->create();
        $otherBook = Book::factory()->create();
        $signed = URL::temporarySignedRoute('books.shared', now()->addDays(7), ['book' => $book->uuid]);

        $this->get(route('books.shared', $book))->assertForbidden();
        $this->get($signed.'&modified=1')->assertForbidden();
        $this->get(str_replace($book->uuid, $otherBook->uuid, $signed))->assertForbidden();
    }

    public function test_missing_files_cannot_be_shared_and_deleted_files_stop_downloading(): void
    {
        Storage::fake('local');
        $book = Book::factory()->create(['object_key' => 'objects/library/missing.txt']);

        $this->actingAs(User::factory()->create())->postJson(route('books.share', $book))->assertNotFound();
        $url = URL::temporarySignedRoute('books.shared', now()->addDays(7), ['book' => $book->uuid]);
        $book->delete();
        auth()->logout();
        $this->get($url)->assertNotFound();
        Storage::disk('local')->assertMissing('objects/library/missing.txt');
    }

    public function test_upload_saves_all_dropped_files_in_the_selected_folder(): void
    {
        Storage::fake('local');
        Category::factory()->create();
        $target = Category::factory()->create();

        $this->actingAs(User::factory()->create())->from(route('catalog.index', ['category' => $target->slug]))->post(route('books.store'), [
            'category_id' => $target->id,
            'files' => [UploadedFile::fake()->create('notes.txt', 1), UploadedFile::fake()->create('report.pdf', 1)],
        ])->assertRedirect(route('catalog.index', ['category' => $target->slug]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('books', 2);
        $this->assertDatabaseHas('books', ['original_filename' => 'notes.txt', 'category_id' => $target->id]);
        $this->assertDatabaseHas('books', ['original_filename' => 'report.pdf', 'category_id' => $target->id]);
        foreach (Book::all() as $book) {
            Storage::disk('local')->assertExists($book->object_key);
        }
    }

    public function test_an_oversized_file_rejects_the_whole_upload_batch(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create())->post(route('books.store'), [
            'files' => [UploadedFile::fake()->create('valid.txt', 1), UploadedFile::fake()->create('too-large.zip', 102401)],
        ])->assertSessionHasErrors('files.1');

        $this->assertDatabaseCount('books', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
