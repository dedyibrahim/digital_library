<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentTextExtractor
{
    /** @return array{text: string, status: string} */
    public function extract(Book $book): array
    {
        $filename = $book->original_filename ?: $book->title;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp', 'docx', 'xlsx', 'pptx', 'txt', 'csv', 'md', 'json', 'xml', 'log', 'tsv'], true)) {
            return ['text' => '', 'status' => 'unsupported'];
        }
        $stream = Storage::readStream($book->storageKey());
        if (! is_resource($stream)) {
            throw new RuntimeException('File indeks tidak tersedia.');
        }
        $temporary = tmpfile();
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException('Tidak dapat menyiapkan ekstraksi.');
        }
        try {
            $bytes = stream_copy_to_stream($stream, $temporary, 104857601);
            if ($bytes === false || $bytes > 104857600) {
                throw new RuntimeException('Ukuran file indeks melebihi batas.');
            }
            fclose($stream);
            fflush($temporary);
            $process = new Process([
                config('document-search.python'), base_path('resources/document-search/extract.py'),
                '--file', stream_get_meta_data($temporary)['uri'],
                '--name', $filename,
                '--tesseract', config('document-search.tesseract'),
                '--tessdata', config('document-search.tessdata'),
                '--languages', config('document-search.languages'),
            ]);
            $process->setTimeout(540);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Ekstraksi dokumen gagal; periksa runtime OCR atau file yang rusak/terkunci.');
            }
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_string($result['text'] ?? null) || ! in_array($result['status'] ?? null, ['ready', 'partial', 'empty', 'unsupported'], true)) {
                throw new RuntimeException('Hasil ekstraksi tidak valid.');
            }

            return ['text' => mb_substr(str_replace("\0", '', $result['text']), 0, 500000), 'status' => $result['status']];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($temporary);
        }
    }
}
