<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObjectStorage
{
    /** @return array{object_key: string, original_filename: string, mime_type: string, file_size: int} */
    public function put(UploadedFile $file, string $objectId, string $bucket = 'library'): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $objectId.($extension ? ".{$extension}" : '');
        $prefix = substr(str_replace('-', '', $objectId), 0, 2);
        $objectKey = "objects/{$bucket}/{$prefix}/{$filename}";

        Storage::putFileAs(dirname($objectKey), $file, basename($objectKey));

        return [
            'object_key' => $objectKey,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
        ];
    }

    public function exists(?string $objectKey): bool
    {
        return filled($objectKey) && Storage::exists($objectKey);
    }

    public function download(string $objectKey, string $filename): StreamedResponse
    {
        return Storage::download($objectKey, $filename);
    }

    public function inline(string $objectKey, string $filename, string $mimeType): StreamedResponse
    {
        return Storage::response($objectKey, $filename, ['Content-Type' => $mimeType], 'inline');
    }

    public function delete(?string $objectKey): void
    {
        if ($objectKey) {
            Storage::delete($objectKey);
        }
    }

    public function id(): string
    {
        return (string) Str::uuid();
    }
}
