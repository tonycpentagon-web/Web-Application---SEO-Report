<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->company().' SEO', 'client_name' => fake()->company(), 'website' => fake()->url(), 'description' => null, 'status' => 'active'];
    }
}
