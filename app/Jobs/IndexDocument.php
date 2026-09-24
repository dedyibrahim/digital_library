<?php

namespace App\Jobs;

use App\Models\Book;
use App\Services\DocumentTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IndexDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $bookId, public string $version) {}

    /**
     * Execute the job.
     */
    public function handle(DocumentTextExtractor $extractor): void
    {
        $query = Book::query()->whereKey($this->bookId)->where('search_version', $this->version);
        $book = (clone $query)->first();
        if (! $book || ! $book->storageKey() || in_array($book->search_status, ['ready', 'partial', 'empty', 'unsupported'], true)) {
            return;
        }
        $query->update(['search_status' => 'processing']);
        try {
            $result = $extractor->extract($book);
            $query->update([
                'search_text' => $result['text'],
                'search_status' => $result['status'],
                'search_indexed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $query->update(['search_status' => 'failed', 'search_text' => null]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Book::query()->whereKey($this->bookId)->where('search_version', $this->version)
            ->update(['search_status' => 'failed', 'search_text' => null]);
    }
}
