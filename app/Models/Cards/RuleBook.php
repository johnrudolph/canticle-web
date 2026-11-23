<?php

namespace App\Models\Cards;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton: the entire rulebook lives in one row (id 1), as a single
 * markdown blob edited via the admin rich-text editor.
 */
class RuleBook extends Model
{
    protected $table = 'rule_book';

    protected $fillable = [
        'content',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], ['content' => '']);
    }
}
