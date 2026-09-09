<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProfileController;
use App\Models\Book;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalog.index');

Route::get('/shared/files/{book}', [CatalogController::class, 'download'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('books.shared');

Route::get('/dashboard', [CatalogController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/drive/folders', [CatalogController::class, 'storeCategory'])->name('categories.store');
    Route::post('/drive/files', [CatalogController::class, 'storeBook'])->name('books.store');
    Route::patch('/drive/files/{book}/rename', [CatalogController::class, 'rename'])->name('books.rename');
    Route::post('/drive/files/{book}/share', [CatalogController::class, 'share'])->name('books.share');
    Route::patch('/drive/files/{book}/star', [CatalogController::class, 'toggleStar'])->name('books.star');
    Route::get('/drive/files/{book}/download', [CatalogController::class, 'download'])->name('books.download');
    Route::get('/drive/files/{book}/content', [CatalogController::class, 'content'])->name('books.content');
    Route::get('/drive/files/{book}', [CatalogController::class, 'open'])->name('books.open');
    Route::delete('/drive/files/{book}', [CatalogController::class, 'destroy'])->name('books.destroy');

    // Compatibility for browser tabs that still have the previous JavaScript bundle loaded.
    Route::post('/books', [CatalogController::class, 'storeBook']);
    Route::get('/books/{book:id}/open', fn (Book $book) => to_route('books.open', $book));
    Route::get('/books/{book:id}/download', fn (Book $book) => to_route('books.download', $book));
    Route::patch('/books/{book:id}/star', [CatalogController::class, 'toggleStar']);
    Route::delete('/books/{book:id}', [CatalogController::class, 'destroy']);
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
