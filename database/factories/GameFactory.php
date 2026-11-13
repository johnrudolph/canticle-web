<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(6)),
            'status' => 'lobby',
            'wanderer_count' => fake()->randomElement([2, 3]),
            'golem_enabled' => false,
            'created_by_user_id' => User::factory(),
        ];
    }
}
