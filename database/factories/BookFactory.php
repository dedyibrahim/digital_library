<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookFactory extends Factory
{
    public function definition(): array
    {
        $total = fake()->numberBetween(1, 12);

        return [
            'category_id' => Category::factory(),
            'title' => fake()->sentence(3),
            'author' => fake()->name(),
            'isbn' => fake()->unique()->isbn13(),
            'description' => fake()->paragraph(2),
            'published_year' => fake()->numberBetween(1980, 2026),
            'cover_url' => null,
            'total_copies' => $total,
            'available_copies' => fake()->numberBetween(0, $total),
        ];
    }
}
