<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class ProductFactory extends Factory
{

    protected $model = \App\Models\Product::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

       
      
     
        return [
            'name' => $this->faker->words(3, true), // اسم منتج من 3 كلمات
            'sku' => $this->faker->unique()->ean13(), // باركود عشوائي ومميز
            'description' => $this->faker->sentence(), // جملة وصف
            'price' => $this->faker->randomFloat(2, 10, 500), // سعر من 10 لـ 500
            'is_active' => $this->faker->boolean(80), // 80% احتمال يكون شغال
            'scalar' => $this->faker->randomElement(['kg', 'pcs', 'l','g','ml']), // بيختار وحدة عشوائية
          
        
        ];
    }
}
