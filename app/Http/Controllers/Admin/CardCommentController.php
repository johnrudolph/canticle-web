<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cards\CardComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin-side half of the comment workflow — leaving a note on a card
 * ("this needs a change") or resolving one once the code's been fixed.
 * The dev-sync API (routes/dev_sync.php) is the other half: how I pull
 * unresolved comments down locally and report fixes back.
 */
class CardCommentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'commentable_type' => ['required', 'string', 'in:location,relic,glyph,golem_card'],
            'commentable_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:1'],
        ]);

        CardComment::create([
            ...$data,
            'kind' => 'comment',
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Comment added.');
    }

    public function resolve(Request $request, CardComment $comment): RedirectResponse
    {
        $data = $request->validate([
            'resolved_note' => ['nullable', 'string'],
        ]);

        $comment->update([
            'resolved_at' => now(),
            'resolved_note' => $data['resolved_note'] ?? null,
        ]);

        return back()->with('success', 'Comment resolved.');
    }
}
