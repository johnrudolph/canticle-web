<?php

namespace App\Models\Cards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'print',
        'name',
        'lore',
        'rule',
        'cost_1',
        'cost_2',
        'cost_3',
        'cost_4',
        'produces',
        'produces_emoji',
        'relics',
        'relic_names',
        'is_starter',
        'has_shard',
        'has_tree',
        'has_back_text',
        'has_relics',
        'has_specific_relics',
        'produces_anything',
        'has_cost',
        'cost_emoji',
    ];

    protected function casts(): array
    {
        return [
            'print' => 'boolean',
            'is_starter' => 'boolean',
            'has_shard' => 'boolean',
            'has_tree' => 'boolean',
            'has_back_text' => 'boolean',
            'has_relics' => 'boolean',
            'has_specific_relics' => 'boolean',
            'produces_anything' => 'boolean',
            'has_cost' => 'boolean',
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
