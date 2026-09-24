<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Category;
use App\Services\ObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', 'in:all,recent,starred'],
            'action' => ['nullable', 'in:upload,folder'],
        ]);

        $books = Book::query()
            ->visibleTo($request->user())
            ->with('category:id,name,slug')
            ->when($filters['search'] ?? null, function ($query, string $search) use ($request): void {
                $query->where(function ($query) use ($search, $request): void {
                    $query->whereLike('title', "%{$search}%")
                        ->orWhereLike('original_filename', "%{$search}%")
                        ->orWhereLike('author', "%{$search}%")
                        ->orWhereLike('mime_type', "%{$search}%")
                        ->orWhereLike('isbn', "%{$search}%")
                        ->orWhereHas('category', fn ($query) => $query->whereLike('name', "%{$search}%"));
                    if ($request->user() && trim($search) !== '') {
                        $query->orWhere(fn ($content) => $content->matchingContent($search));
                    }
                });
            })
            ->when($filters['category'] ?? null, fn ($query, string $slug) => $query->whereHas('category', fn ($query) => $query->where('slug', $slug))
            )
            ->when(($filters['view'] ?? 'all') === 'starred', fn ($query) => $query->where('is_starred', true))
            ->when(
                ($filters['view'] ?? 'all') === 'recent',
                fn ($query) => $query->orderByDesc('last_opened_at')->orderByDesc('updated_at'),
                fn ($query) => $query->latest(),
            )
            ->paginate(9)
            ->withQueryString();

        if ($request->user() && filled($filters['search'] ?? null)) {
            $needle = preg_split('/\s+/u', trim($filters['search']))[0];
            $books->through(function (Book $book) use ($needle): Book {
                $position = mb_stripos($book->search_text ?? '', $needle);
                if ($position !== false) {
                    $book->setAttribute('search_excerpt', ($position > 60 ? '…' : '').mb_substr($book->search_text, max(0, $position - 60), 220));
                }

                return $book;
            });
        }

        return Inertia::render('Catalog/Index', [
            'books' => $books,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'category' => $filters['category'] ?? '',
                'view' => $filters['view'] ?? 'all',
                'action' => $filters['action'] ?? '',
            ],
            'stats' => [
                'titles' => Book::visibleTo($request->user())->count(),
                'copies' => Book::visibleTo($request->user())->sum('total_copies'),
                'available' => Book::visibleTo($request->user())->sum('available_copies'),
            ],
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:categories,name']]);

        Category::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(5)),
        ]);

        return back()->with('success', 'Folder berhasil dibuat.');
    }

    public function storeBook(Request $request, ObjectStorage $storage): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:102400'],
        ]);

        $categoryId = $validated['category_id'] ?? Category::query()->value('id');
        if (! $categoryId) {
            $categoryId = Category::create(['name' => 'Umum', 'slug' => 'umum'])->id;
        }

        foreach ($request->file('files') as $file) {
            $objectId = $storage->id();
            Book::create([
                'uuid' => $objectId,
                'bucket' => 'library',
                'category_id' => $categoryId,
                'title' => $file->getClientOriginalName(),
                'author' => $request->user()->name,
                ...$storage->put($file, $objectId),
                'total_copies' => 1,
                'available_copies' => 1,
            ]);
        }

        return back()->with('success', count($validated['files']).' file berhasil diunggah.');
    }

    public function toggleStar(Book $book): RedirectResponse
    {
        $book->authorizeDesktopOwner(request()->user());
        $book->update(['is_starred' => ! $book->is_starred]);

        return back()->with('success', $book->is_starred ? 'Ditambahkan ke Berbintang.' : 'Dihapus dari Berbintang.');
    }

    public function rename(Request $request, Book $book): RedirectResponse
    {
        $book->authorizeDesktopOwner($request->user());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_in:.,..', 'regex:~^[^/\\\\\x00-\x1F\x7F]+$~u'],
        ], [
            'name.required' => 'Nama file wajib diisi.',
            'name.regex' => 'Nama file tidak boleh mengandung garis miring atau karakter kontrol.',
        ]);

        $name = trim($validated['name']);
        if ($book->original_filename && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo($book->original_filename, PATHINFO_EXTENSION))) {
            throw ValidationException::withMessages(['name' => 'Ekstensi file harus tetap sama.']);
        }

        $book->update([
            'title' => $name,
            'original_filename' => $book->original_filename ? $name : null,
        ]);

        return back()->with('success', 'Nama file berhasil diperbarui.');
    }

    public function share(Book $book, ObjectStorage $storage): JsonResponse
    {
        $book->authorizeDesktopOwner(request()->user());
        abort_unless($storage->exists($book->storageKey()), 404, 'File tidak tersedia.');
        $expiresAt = now()->addDays(7);

        return response()->json([
            'url' => URL::temporarySignedRoute('books.shared', $expiresAt, ['book' => $book->uuid]),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function download(Book $book, ObjectStorage $storage): StreamedResponse
    {
        if (! request()->routeIs('books.shared')) {
            $book->authorizeDesktopOwner(request()->user());
        }
        abort_unless($storage->exists($book->storageKey()), 404, 'File tidak tersedia.');
        $book->update(['last_opened_at' => now()]);

        return $storage->download($book->storageKey(), $book->original_filename ?? $book->title);
    }

    public function open(Book $book, ObjectStorage $storage): SymfonyResponse
    {
        $book->authorizeDesktopOwner(request()->user());
        $book->update(['last_opened_at' => now()]);

        return $this->content($book, $storage);
    }

    public function content(Book $book, ObjectStorage $storage): SymfonyResponse
    {
        $book->authorizeDesktopOwner(request()->user());
        abort_unless($storage->exists($book->storageKey()), 404, 'File tidak tersedia.');

        $previewable = str_starts_with($book->mime_type ?? '', 'image/')
            || str_starts_with($book->mime_type ?? '', 'audio/')
            || str_starts_with($book->mime_type ?? '', 'video/')
            || str_starts_with($book->mime_type ?? '', 'text/')
            || $book->mime_type === 'application/pdf';

        if (! $previewable) {
            return $storage->download($book->storageKey(), $book->original_filename ?? $book->title);
        }

        return $storage->inline($book->storageKey(), $book->original_filename ?? $book->title, $book->mime_type);
    }

    public function destroy(Book $book, ObjectStorage $storage): RedirectResponse
    {
        $book->authorizeDesktopOwner(request()->user());
        $storage->delete($book->storageKey());
        $book->delete();

        return back()->with('success', 'Dokumen berhasil dihapus.');
    }
}
