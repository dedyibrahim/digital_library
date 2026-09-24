<?php

namespace Tests\Feature;

use App\Jobs\IndexDocument;
use App\Models\Book;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_search_requires_login_and_respects_desktop_ownership(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $public = Book::factory()->create(['title' => 'scan.pdf']);
        $private = Book::factory()->create(['title' => 'private.pdf']);
        $public->forceFill(['search_text' => 'sertifikat tanah nusantara', 'search_status' => 'ready'])->save();
        $private->forceFill(['desktop_user_id' => $other->id, 'search_text' => 'sertifikat tanah nusantara', 'search_status' => 'ready'])->save();
        $this->get('/?search=sertifikat')->assertInertia(fn (Assert $page) => $page->has('books.data', 0));
        $this->actingAs($user)->get('/?search=sertifikat%20tanah')
            ->assertInertia(fn (Assert $page) => $page->has('books.data', 1)
                ->where('books.data.0.id', $public->id)->missing('books.data.0.search_text')->missing('books.data.0.search_version'));
    }

    public function test_file_creation_and_replacement_queue_indexing_but_rename_does_not(): void
    {
        Queue::fake();
        $book = Book::factory()->create(['object_key' => 'objects/first.txt']);
        Queue::assertPushed(IndexDocument::class, fn ($job) => $job->bookId === $book->id && $job->connection === 'document-indexing' && $job->queue === 'indexing');
        $version = $book->search_version;
        $book->update(['title' => 'renamed.txt', 'is_starred' => true]);
        Queue::assertPushed(IndexDocument::class, 1);
        $book->forceFill(['search_text' => 'old text', 'search_status' => 'ready'])->save();
        $book->update(['object_key' => 'objects/replaced.txt']);
        Queue::assertPushed(IndexDocument::class, 2);
        $this->assertNotSame($version, $book->search_version);
        $this->assertNull($book->search_text);
    }

    public function test_job_indexes_content_and_ignores_stale_or_deleted_files(): void
    {
        Queue::fake();
        $book = Book::factory()->create(['object_key' => 'objects/document.txt']);
        $extractor = $this->mock(DocumentTextExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn(['text' => 'hasil OCR', 'status' => 'ready']);
        (new IndexDocument($book->id, $book->search_version))->handle($extractor);
        $this->assertSame('hasil OCR', $book->fresh()->search_text);
        (new IndexDocument($book->id, 'stale-version'))->handle($extractor);
        $book->delete();
        (new IndexDocument($book->id, $book->search_version))->handle($extractor);
    }

    public function test_replacement_during_extraction_cannot_publish_old_text(): void
    {
        Queue::fake();
        $book = Book::factory()->create(['object_key' => 'objects/old.txt']);
        $extractor = $this->mock(DocumentTextExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturnUsing(function () use ($book): array {
            $book->update(['object_key' => 'objects/new.txt']);

            return ['text' => 'old secret', 'status' => 'ready'];
        });
        (new IndexDocument($book->id, $book->search_version))->handle($extractor);
        $this->assertNull($book->fresh()->search_text);
        $this->assertSame('pending', $book->fresh()->search_status);
    }

    public function test_failed_extraction_is_visible_and_can_be_requeued(): void
    {
        Queue::fake();
        $book = Book::factory()->create(['object_key' => 'objects/broken.pdf']);
        $extractor = $this->mock(DocumentTextExtractor::class);
        $extractor->shouldReceive('extract')->once()->andThrow(new RuntimeException('Broken PDF'));
        try {
            (new IndexDocument($book->id, $book->search_version))->handle($extractor);
            $this->fail('Expected extraction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $book->fresh()->search_status);
        }
        $this->artisan('search:reindex')->assertSuccessful();
        $this->assertSame('pending', $book->fresh()->search_status);
        Queue::assertPushed(IndexDocument::class, 2);
        $this->artisan('search:reindex', ['--status' => true])->assertSuccessful();
    }

    public function test_extractor_reads_real_text_from_private_storage(): void
    {
        if (! is_file(config('document-search.python'))) {
            $this->markTestSkipped('Optional Python extraction runtime is not installed.');
        }
        Queue::fake();
        Storage::fake();
        Storage::put('objects/test.txt', 'Sertifikat tanah Nusantara');
        $book = Book::factory()->create(['object_key' => 'objects/test.txt', 'original_filename' => 'scan.txt']);
        $result = app(DocumentTextExtractor::class)->extract($book);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('Sertifikat tanah Nusantara', $result['text']);
    }
}
