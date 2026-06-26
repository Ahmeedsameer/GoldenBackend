<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company . ' Branch',
            'address' => $this->faker->address,
            'username' => $this->faker->unique()->userName,
            'password' => Hash::make('password123'),
            'status' => $this->faker->randomElement(['active', 'inactive']),
        ];
    }
}