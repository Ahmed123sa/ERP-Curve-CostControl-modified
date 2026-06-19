<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word() . ' - ' . fake()->randomNumber(4),
            'unit' => fake()->randomElement(['kg', 'g', 'L', 'piece', 'box']),
            'is_active' => true,
            'default_cost' => fake()->randomFloat(2, 1, 100),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
