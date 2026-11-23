<?php

namespace App\Models\Cards;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A note left on a card (Location/Relic/Glyph/GolemCard) by whoever's
 * editing it in prod — either a plain request for a code change
 * ($kind === 'comment') or the reason a card was soft-deleted
 * ($kind === 'deletion', auto-created alongside the delete). The
 * canticle:pull-changes / canticle:resolve-comment artisan commands are
 * the dev-side half of this workflow — see routes/dev_sync.php.
 *
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property string $kind
 * @property string $body
 * @property int|null $created_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_note
 */
class CardComment extends Model
{
    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'kind',
        'body',
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
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
