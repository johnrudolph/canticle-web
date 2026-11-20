import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GolemTurnSegment } from '@/lib/golem-turn-spotlight';
import type { GlyphCatalogEntry, GolemCardCatalogEntry } from '@/types/game';
import { describe } from './activity-log';

/**
 * Walks the player through exactly what the Golem did this round,
 * one card at a time — full printed text, then a plain-English line
 * per thing that card's resolution actually caused (reusing
 * ActivityLog's own describe() so the wording never drifts from the
 * scrolling log). The board stays in sync: show.tsx overrides the
 * Golem's displayed cell and every Wanderer's displayed HP to match
 * wherever the player has clicked to in this queue, not the final
 * state, until they've clicked through every card.
 */
export function GolemTurnSpotlightDialog({
    open,
    segment,
    index,
    total,
    activeGlyphs,
    glyphCatalog,
    golemCardCatalog,
    locationNames,
    relicNames,
    glyphNames,
    golemCardNames,
    onContinue,
}: {
    open: boolean;
    segment: GolemTurnSegment | null;
    index: number;
    total: number;
    activeGlyphs: string[];
    glyphCatalog: Record<string, GlyphCatalogEntry>;
    golemCardCatalog: Record<string, GolemCardCatalogEntry>;
    locationNames: Record<string, string>;
    relicNames: Record<string, string>;
    glyphNames: Record<string, string>;
    golemCardNames: Record<string, string>;
    onContinue: () => void;
}) {
    if (!segment) return null;

    const cardKey = segment.marker.payload.card_key as string | undefined;
    const name =
        (segment.marker.payload.name as string | undefined) ??
        (cardKey ? golemCardCatalog[cardKey]?.name : undefined) ??
        cardKey ??
        'Unknown card';
    const action =
        (segment.marker.payload.action as string | undefined) ??
        (cardKey ? golemCardCatalog[cardKey]?.action : undefined);

    const noEffect =
        segment.marker.type === 'golem_card_tapped' ||
        segment.marker.type === 'golem_card_skipped';
    const statusLine =
        segment.marker.type === 'golem_card_tapped'
            ? 'Face-down (tapped) — this card does NOT resolve.'
            : segment.marker.type === 'golem_card_skipped'
              ? 'Skipped (Parallel) — discarded unresolved, next card drawn in its place.'
              : null;

    return (
        <Dialog open={open} onOpenChange={() => {}}>
            <DialogContent
                className="sm:max-w-lg [&>button]:hidden"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onPointerDownOutside={(e) => e.preventDefault()}
            >
                <DialogHeader className="min-w-0">
                    <DialogTitle className="flex items-center justify-between gap-2 text-xl break-words">
                        <span>👺 {name}</span>
                        <span className="shrink-0 text-xs font-normal text-muted-foreground">
                            Card {index + 1} of {total}
                        </span>
                    </DialogTitle>
                </DialogHeader>

                {statusLine && (
                    <p className="min-w-0 rounded-md border-2 border-destructive/50 bg-destructive/10 p-2 text-center text-sm font-semibold break-words text-destructive">
                        {statusLine}
                    </p>
                )}

                {action && (
                    <p
                        className={`min-w-0 rounded-md border p-3 text-sm break-words ${
                            noEffect
                                ? 'text-muted-foreground line-through opacity-60'
                                : 'bg-muted/40'
                        }`}
                    >
                        {action}
                    </p>
                )}

                {activeGlyphs.length > 0 && (
                    <div className="min-w-0">
                        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Active Glyphs
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {activeGlyphs.map((key) => (
                                <span
                                    key={key}
                                    className="rounded-full border bg-muted/40 px-2 py-0.5 text-xs"
                                    title={glyphCatalog[key]?.description}
                                >
                                    {glyphCatalog[key]?.name ?? key}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {segment.followups.length > 0 && (
                    <div className="min-w-0 space-y-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            What happened
                        </p>
                        {segment.followups.map((event) => (
                            <p
                                key={event.id}
                                className="min-w-0 text-sm break-words"
                            >
                                {describe(
                                    event,
                                    locationNames,
                                    relicNames,
                                    glyphNames,
                                    golemCardNames,
                                )}
                            </p>
                        ))}
                    </div>
                )}

                <DialogFooter>
                    <Button size="sm" onClick={onContinue}>
                        {index + 1 >= total ? 'Done' : 'Continue'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
