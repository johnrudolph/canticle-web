<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cards\CardComment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared CRUD shape for the 4 card types (Locations/Relics/Glyphs/Golem
 * Cards) — same list/create/edit/delete-with-reason behavior, different
 * model class, validation rules, and morph-map key. See
 * LocationController etc. for the ~15-line concrete subclasses.
 *
 * `key` is deliberately never accepted from the request on store() or
 * update() — it's derived once from `name` at creation and frozen
 * forever after, since GameEngine.php hardcodes these keys throughout.
 */
abstract class CardController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Morph map key used for this card type's comments — see AppServiceProvider. */
    abstract protected function morphType(): string;

    /** The page component under resources/js/pages/admin/ to render for the list view. */
    abstract protected function pageComponent(): string;

    /** @return array<string, mixed> */
    abstract protected function rules(): array;

    /**
     * Computes server-side/derived columns (e.g. slot_emoji from slot)
     * that are never accepted directly from the request — they're just
     * absent from rules(), so validate() won't return them, and this
     * fills them back in right before persisting. Default: no-op.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function beforeSave(array $data): array
    {
        return $data;
    }

    /** Extra Inertia props beyond `cards` — e.g. a Select's option list. */
    protected function extraProps(): array
    {
        return [];
    }

    /** The page component under resources/js/pages/admin/ to render for the single-card detail view. */
    abstract protected function detailPageComponent(): string;

    public function index(): Response
    {
        $cards = $this->modelClass()::query()
            ->withTrashed()
            ->with('comments')
            ->orderBy('name')
            ->get();

        return Inertia::render($this->pageComponent(), [
            'cards' => $cards,
            ...$this->extraProps(),
        ]);
    }

    public function show(int $id): Response
    {
        $card = $this->modelClass()::query()
            ->withTrashed()
            ->with('comments')
            ->findOrFail($id);

        return Inertia::render($this->detailPageComponent(), [
            'card' => $card,
            ...$this->extraProps(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->beforeSave($request->validate($this->rules()));
        $data['key'] = $this->uniqueKey($data['name']);

        $this->modelClass()::create($data);

        return back()->with('success', 'Card created.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $card = $this->modelClass()::withTrashed()->findOrFail($id);
        $data = $this->beforeSave($request->validate($this->rules()));

        $card->update($data);

        return back()->with('success', 'Card updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $card = $this->modelClass()::findOrFail($id);

        CardComment::create([
            'commentable_type' => $this->morphType(),
            'commentable_id' => $card->id,
            'kind' => 'deletion',
            'body' => $request->string('reason')->toString(),
            'created_by' => $request->user()?->id,
        ]);

        $card->delete();

        return back()->with('success', 'Card deleted.');
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name);
        $key = $base;
        $suffix = 2;

        while ($this->modelClass()::withTrashed()->where('key', $key)->exists()) {
            $key = "{$base}-{$suffix}";
            $suffix++;
        }

        return $key;
    }
}
