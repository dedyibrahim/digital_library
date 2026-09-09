<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_page_lists_available_books(): void
    {
        $category = Category::factory()->create(['name' => 'Teknologi', 'slug' => 'teknologi']);
        Book::factory()->for($category)->create(['title' => 'Laravel untuk Pemula']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Index')
                ->has('books.data', 1)
                ->where('books.data.0.title', 'Laravel untuk Pemula')
                ->where('stats.titles', 1));
    }

    public function test_catalog_can_filter_books_by_search_and_category(): void
    {
        $technology = Category::factory()->create(['name' => 'Teknologi', 'slug' => 'teknologi']);
        $history = Category::factory()->create(['name' => 'Sejarah', 'slug' => 'sejarah']);
        Book::factory()->for($technology)->create(['title' => 'Belajar PostgreSQL']);
        Book::factory()->for($history)->create(['title' => 'Sejarah Nusantara']);

        $this->get('/?search=PostgreSQL&category=teknologi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('books.data', 1)
                ->where('books.data.0.title', 'Belajar PostgreSQL'));
    }

    public function test_search_finds_an_uploaded_file_by_filename_type_owner_and_folder(): void
    {
        $category = Category::factory()->create(['name' => 'Dokumen Proyek', 'slug' => 'dokumen-proyek']);
        Book::factory()->for($category)->create([
            'title' => 'laporan-keuangan-2026.xlsx',
            'original_filename' => 'laporan-keuangan-2026.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'author' => 'Admin Pustaka',
        ]);
        Book::factory()->create(['title' => 'file-lain.pdf']);

        foreach (['laporan-keuangan', 'spreadsheet', 'Admin Pustaka', 'Dokumen Proyek'] as $search) {
            $this->get('/?search='.urlencode($search))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('books.data', 1)
                    ->where('books.data.0.original_filename', 'laporan-keuangan-2026.xlsx')
                    ->where('filters.search', $search));
        }
    }
}
