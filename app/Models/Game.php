<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'status',
        'wanderer_count',
        'golem_enabled',
        'round',
        'phase',
        'state',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'golem_enabled' => 'boolean',
            'state' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(GameRole::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
