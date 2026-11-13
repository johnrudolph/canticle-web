<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameRole>
 */
class GameRoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'role_key' => fake()->randomElement(['wanderer_a', 'wanderer_b', 'emperor']),
            'user_id' => null,
            'is_ready' => false,
        ];
    }
}
