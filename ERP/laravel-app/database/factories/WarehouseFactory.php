<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city() . ' Warehouse',
            'type' => fake()->randomElement(['main', 'sub', 'branch']),
            'is_active' => true,
        ];
    }
}
