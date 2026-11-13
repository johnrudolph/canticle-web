<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'role_key',
        'user_id',
        'is_ready',
    ];

    protected function casts(): array
    {
        return [
            'is_ready' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
