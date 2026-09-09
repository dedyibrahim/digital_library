<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin Pustaka',
            'email' => 'admin@pustaka.test',
            'password' => 'password',
        ]);

        collect(['Teknologi', 'Sains', 'Sejarah', 'Bisnis', 'Sastra', 'Psikologi'])
            ->each(function (string $name): void {
                $category = Category::create([
                    'name' => $name,
                    'slug' => str($name)->slug(),
                ]);

                Book::factory(3)->for($category)->create();
            });
    }
}
