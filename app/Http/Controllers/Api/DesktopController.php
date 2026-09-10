<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use App\Services\ObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DesktopController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        return response()->json([
            'token' => $user->createToken('desktop:'.$data['device_name'], ['desktop:sync'], now()->addDays(30))->plainTextToken,
            'user' => $user->only(['id', 'name', 'email', 'role']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $this->authorizeToken($request);

        return response()->json(['user' => $request->user()->only(['id', 'name', 'email', 'role'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authorizeToken($request);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeToken($request);
        $data = $request->validate(['root' => ['required', 'uuid']]);

        return response()->json(['files' => Book::where('desktop_user_id', $request->user()->id)
            ->where('sync_root', $data['root'])->orderBy('id')
            ->get(['uuid', 'sync_path', 'sync_hash', 'file_size', 'updated_at'])]);
    }

    public function store(Request $request, ObjectStorage $storage): JsonResponse
    {
        $this->authorizeToken($request);
        $data = $request->validate([
            'root' => ['required', 'uuid'],
            'path' => ['required', 'string', 'max:1024'],
            'base_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'file' => ['required', 'file', 'max:102400'],
        ]);
        foreach (explode('/', $data['path']) as $part) {
            if ($part === '' || in_array($part, ['.', '..'], true) || preg_match('/[\\\\:\x00-\x1f\x7f<>"|?*]/', $part) || preg_match('/[. ]$/', $part)) {
                throw ValidationException::withMessages(['path' => 'Path file tidak valid.']);
            }
        }
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $objectKey = null;
        $oldKey = null;
        try {
            $book = DB::transaction(function () use ($request, $data, $file, $hash, $storage, &$objectKey, &$oldKey): Book {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $book = Book::where('desktop_user_id', $request->user()->id)
                    ->where('sync_root', $data['root'])->where('sync_path_hash', hash('sha256', mb_strtolower($data['path'])))->first();
                if ($book && $book->sync_hash === $hash) {
                    return $book;
                }
                abort_if($book && $book->sync_hash !== ($data['base_hash'] ?? null), 409, 'File server berubah. Sinkronkan ulang untuk menyimpan salinan konflik.');
                $category = Category::firstOrCreate(['slug' => 'desktop-sync'], ['name' => 'Desktop Sync']);
                $metadata = $storage->put($file, $storage->id());
                $objectKey = $metadata['object_key'];
                abort_unless($storage->exists($objectKey), 500, 'Penyimpanan file gagal.');
                $oldKey = $book?->storageKey();
                $book ??= new Book;
                $book->forceFill([
                    ...$metadata,
                    'desktop_user_id' => $request->user()->id,
                    'sync_root' => $data['root'],
                    'sync_path' => $data['path'],
                    'sync_path_hash' => hash('sha256', mb_strtolower($data['path'])),
                    'sync_hash' => $hash,
                    'category_id' => $category->id,
                    'title' => basename($data['path']),
                    'original_filename' => basename($data['path']),
                    'author' => $request->user()->name,
                    'bucket' => 'library',
                ])->save();

                return $book;
            });
        } catch (\Throwable $exception) {
            if ($objectKey) {
                $storage->delete($objectKey);
            }
            throw $exception;
        }
        if ($oldKey) {
            $storage->delete($oldKey);
        }

        return response()->json(['file' => $book->only(['uuid', 'sync_path', 'sync_hash', 'file_size'])]);
    }

    public function content(Request $request, Book $book, ObjectStorage $storage): StreamedResponse
    {
        $this->authorizeToken($request);
        abort_unless((int) $book->desktop_user_id === $request->user()->id, 404);
        abort_unless($storage->exists($book->storageKey()), 404);

        return $storage->download($book->storageKey(), $book->original_filename);
    }

    private function authorizeToken(Request $request): void
    {
        abort_unless($request->bearerToken() && $request->user()->tokenCan('desktop:sync'), 403);
    }
}
