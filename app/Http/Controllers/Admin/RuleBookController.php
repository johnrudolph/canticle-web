<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cards\RuleBook;
use App\Models\Cards\RuleBookRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RuleBookController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/rulebook', [
            'content' => RuleBook::current()->content,
        ]);
    }

    /**
     * A justification is only required when the content actually
     * changed — re-saving identical text (or just hitting Save with no
     * edits) shouldn't force a justification prompt. The frontend
     * already knows this (it only prompts when the editor's value
     * differs from what was loaded), but this is enforced here too
     * since the client can't be trusted to always ask.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'content' => ['nullable', 'string'],
            'justification' => ['nullable', 'string'],
        ]);

        $ruleBook = RuleBook::current();
        $newContent = $data['content'] ?? '';

        if ($newContent !== ($ruleBook->content ?? '')) {
            $justification = trim((string) ($data['justification'] ?? ''));

            if ($justification === '') {
                throw ValidationException::withMessages([
                    'justification' => 'A justification is required when changing the rulebook.',
                ]);
            }

            RuleBookRevision::create([
                'content' => $newContent,
                'justification' => $justification,
                'created_by' => $request->user()?->id,
            ]);
        }

        $ruleBook->update(['content' => $newContent]);

        return back()->with('success', 'Rulebook saved.');
    }
}
