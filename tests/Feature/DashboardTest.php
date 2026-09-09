<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_displays_the_drive_library_interface(): void
    {
        $category = Category::factory()->create();
        Book::factory()->for($category)->create(['file_path' => 'books/example.pdf', 'file_size' => 2048, 'is_starred' => true]);
        Book::factory()->for($category)->create(['file_path' => null, 'file_size' => null, 'is_starred' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Index')
                ->where('stats.titles', 2)
                ->has('books.data', 2)
                ->has('categories', 1));
    }
}
