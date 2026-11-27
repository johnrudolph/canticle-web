import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { LocationCatalogEntry } from '@/types/game';

/** Branch name (e.g. "Art") -> the emoji whichever Location produces it uses. */
function branchEmoji(
    branch: string,
    locationCatalog: Record<string, LocationCatalogEntry>,
): string | null {
    const match = Object.values(locationCatalog).find(
        (l) => l.produces === branch,
    );
    return match?.produces_emoji ?? null;
}

/**
 * An insight from the future: "Search the Emperor's draw pile, and
 * place one card into the Emperor's hand." The deck is normally
 * hidden information — this only ever renders with a deck the server
 * just revealed in direct response to this Wanderer's own
 * peek_location_deck call (see show.tsx), never via the shared poll.
 * Choosing a card here fires the real use_relic call that moves it
 * into the Emperor's hand, shuffles the rest, trashes this Relic, and
 * grants 📜.
 */
export function InsightFromTheFutureDialog({
    open,
    onOpenChange,
    deck,
    locationCatalog,
    processing,
    onChoose,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    deck: string[];
    locationCatalog: Record<string, LocationCatalogEntry>;
    processing: boolean;
    onChoose: (locationKey: string) => void;
}) {
    // Several identical-looking Location keys can appear in the deck;
    // index-qualify so each row's key stays unique even when the name
    // (and the server-side key) repeats.
    const counts = new Map<string, number>();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        ⏳ An insight from the future
                    </DialogTitle>
                    <DialogDescription>
                        Choose one Location to place face-up into the Emperor's
                        hand. The rest of the deck is shuffled afterward.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex max-h-96 flex-col gap-1 overflow-y-auto">
                    {deck.map((key) => {
                        const loc = locationCatalog[key];
                        const n = counts.get(key) ?? 0;
                        counts.set(key, n + 1);

                        return (
                            <Button
                                key={`${key}-${n}`}
                                variant="outline"
                                size="sm"
                                className="h-auto flex-col items-start justify-start gap-1 py-2 text-left whitespace-normal"
                                disabled={processing}
                                onClick={() => onChoose(key)}
                            >
                                <span>
                                    {loc?.produces_emoji} {loc?.name ?? key}
                                </span>
                                {loc && (
                                    <span className="text-xs text-muted-foreground">
                                        {loc.costs.length === 0
                                            ? 'Adjacency: none'
                                            : `Adjacency: ${loc.costs
                                                  .map((c) => {
                                                      const emoji =
                                                          branchEmoji(
                                                              c,
                                                              locationCatalog,
                                                          );
                                                      return emoji
                                                          ? `${emoji} ${c}`
                                                          : c;
                                                  })
                                                  .join(', ')}`}
                                    </span>
                                )}
                                {loc?.back_text && (
                                    <span className="text-xs text-muted-foreground whitespace-pre-wrap">
                                        {loc.back_text}
                                    </span>
                                )}
                            </Button>
                        );
                    })}
                </div>
            </DialogContent>
        </Dialog>
    );
}
