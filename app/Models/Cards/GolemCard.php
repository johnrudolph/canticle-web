<?php

namespace App\Models\Cards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GolemCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'print',
        'name',
        'action',
    ];

    protected function casts(): array
    {
        return [
            'print' => 'boolean',
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
