<?php

namespace App\Models\Cards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Relic extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'print',
        'name',
        'location',
        'slot',
        'type',
        'symbol',
        'effect',
        'slot_emoji',
        'is_action',
        'is_passive',
        'is_event',
    ];

    protected function casts(): array
    {
        return [
            'print' => 'boolean',
            'is_action' => 'boolean',
            'is_passive' => 'boolean',
            'is_event' => 'boolean',
        ];
    }

    /**
     * @return MorphMany<CardComment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(CardComment::class, 'commentable');
    }
}
