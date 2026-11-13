<?php

namespace App\Game;

use App\Models\Game;
use App\Models\GameEvent;

final class EventLog
{
    /**
     * @param  list<array{type: string, role_key: ?string, payload: array<string, mixed>}>  $events
     */
    public static function record(Game $game, array $events): void
    {
        foreach ($events as $event) {
            GameEvent::create([
                'game_id' => $game->id,
                'role_key' => $event['role_key'],
                'type' => $event['type'],
                'payload' => $event['payload'],
            ]);
        }
    }
}
