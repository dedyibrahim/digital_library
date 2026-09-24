<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('search:reindex {--force : Indeks ulang seluruh file} {--status : Tampilkan jumlah per status saja}')]
#[Description('Antrekan ekstraksi isi dokumen dan OCR lokal')]
class ReindexDocuments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('status')) {
            $this->table(['Status', 'Jumlah'], Book::query()->selectRaw('search_status, count(*) as total')->groupBy('search_status')->get()->map(fn (Book $book): array => [$book->search_status, $book->total]));

            return self::SUCCESS;
        }
        $count = 0;
        Book::query()->when(! $this->option('force'), fn ($query) => $query->whereIn('search_status', ['pending', 'failed']))
            ->chunkById(100, function ($books) use (&$count): void {
                foreach ($books as $book) {
                    if (! $book->storageKey()) {
                        continue;
                    }
                    $book->forceFill(['search_version' => (string) Str::uuid(), 'search_status' => 'pending', 'search_text' => null, 'search_indexed_at' => null])->save();
                    $count++;
                }
            });
        $this->info("{$count} file masuk antrean indexing.");

        return self::SUCCESS;
    }
}
