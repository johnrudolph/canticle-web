<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cards\CardComment;
use App\Models\Cards\Glyph;
use App\Models\Cards\GolemCard;
use App\Models\Cards\Location;
use App\Models\Cards\Relic;
use App\Models\Cards\RuleBook;
use App\Models\Cards\RuleBookRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gated by EnsureDebugApiKey (routes/dev_sync.php). Lets the local dev
 * side (canticle:pull-changes / canticle:resolve-comment) see what
 * needs fixing in GameEngine.php without raw database access to prod.
 */
class DevSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CardComment::query()->latest();

        // Default (no ?resolved= at all, or resolved=0) is unresolved
        // only — the whole point of this endpoint is "what still needs
        // fixing." Pass ?resolved=1 to see everything, including
        // already-resolved comments.
        if (! $request->boolean('resolved', false)) {
            $query->whereNull('resolved_at');
        }

        $comments = $query->get()->map(function (CardComment $comment) {
            // The card may itself be soft-deleted (common for
            // kind=deletion comments) — look it up withTrashed() via
            // each model directly, since morphTo() alone won't bypass
            // SoftDeletes' default scope.
            $card = $this->findCardEvenIfTrashed($comment->commentable_type, $comment->commentable_id);

            return [
                'id' => $comment->id,
                'kind' => $comment->kind,
                'body' => $comment->body,
                'resolved_at' => $comment->resolved_at,
                'resolved_note' => $comment->resolved_note,
                'created_at' => $comment->created_at,
                'card' => [
                    'type' => $comment->commentable_type,
                    'id' => $comment->commentable_id,
                    'key' => $card?->key,
                    'name' => $card?->name,
                ],
            ];
        });

        return response()->json(['comments' => $comments]);
    }

    public function resolve(Request $request, CardComment $comment): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $comment->update([
            'resolved_at' => now(),
            'resolved_note' => $data['note'] ?? null,
        ]);

        return response()->json(['comment' => $comment]);
    }

    /**
     * The current rulebook text, regardless of whether any revision on
     * it has been resolved — unlike rulebookRevisions() below, this is
     * "what does the rulebook say right now," not "what's outstanding."
     * canticle:pull-changes always writes this straight into
     * rules/rules.md so that file mirrors prod the same way the card
     * JSON snapshots do.
     */
    public function rulebook(): JsonResponse
    {
        return response()->json(['content' => RuleBook::current()->content]);
    }

    public function rulebookRevisions(Request $request): JsonResponse
    {
        $query = RuleBookRevision::query()->latest();

        if (! $request->boolean('resolved', false)) {
            $query->whereNull('resolved_at');
        }

        return response()->json(['revisions' => $query->get()]);
    }

    public function resolveRulebookRevision(Request $request, RuleBookRevision $revision): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $revision->update([
            'resolved_at' => now(),
            'resolved_note' => $data['note'] ?? null,
        ]);

        return response()->json(['revision' => $revision]);
    }

    private function findCardEvenIfTrashed(string $morphType, int $id): Location|Relic|Glyph|GolemCard|null
    {
        return match ($morphType) {
            'location' => Location::withTrashed()->find($id),
            'relic' => Relic::withTrashed()->find($id),
            'glyph' => Glyph::withTrashed()->find($id),
            'golem_card' => GolemCard::withTrashed()->find($id),
            default => null,
        };
    }
}
