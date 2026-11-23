<?php

namespace App\Models\Cards;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved rulebook change, with the designer's own justification for
 * why — e.g. "Roads: changed from 'move into a space with a Road costs
 * nothing' to 'move OUT of a space with a Road costs nothing' because
 * the old wording let you chain free moves across adjacent roads
 * indefinitely." canticle:pull-changes surfaces unresolved ones so that
 * justification makes it into the PR that actually updates the engine
 * to match, same shape as CardComment's workflow.
 *
 * @property int $id
 * @property string $content
 * @property string $justification
 * @property int|null $created_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_note
 */
class RuleBookRevision extends Model
{
    protected $fillable = [
        'content',
        'justification',
        'created_by',
        'resolved_at',
        'resolved_note',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
