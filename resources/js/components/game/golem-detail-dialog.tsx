import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    GlyphCatalogEntry,
    GolemCardCatalogEntry,
    GolemState,
} from '@/types/game';

export function GolemDetailDialog({
    open,
    onOpenChange,
    golem,
    golemCardCatalog,
    glyphCatalog,
    golemCardsThisRound,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    golem: GolemState;
    golemCardCatalog: Record<string, GolemCardCatalogEntry>;
    glyphCatalog: Record<string, GlyphCatalogEntry>;
    golemCardsThisRound: number;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="text-2xl">👺 The Golem</DialogTitle>
                    <DialogDescription>
                        Plays {golemCardsThisRound} cards this round · Shame:{' '}
                        {golem.shame}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 text-sm">
                    <div>
                        <p className="mb-2 font-medium">
                            Upcoming cards (rightmost resolves next)
                        </p>
                        <div className="flex flex-col gap-2">
                            {golem.revealed.length === 0 && (
                                <p className="text-muted-foreground">
                                    None left to reveal.
                                </p>
                            )}
                            {[...golem.revealed].reverse().map((entry, i) => {
                                const card = golemCardCatalog[entry.key];
                                return (
                                    <div
                                        key={`${entry.key}-${i}`}
                                        className={`rounded-md border p-2 ${entry.tapped ? 'opacity-50' : ''}`}
                                    >
                                        <p className="font-medium">
                                            {entry.tapped ? '🂠 Tapped — ' : ''}
                                            {card?.name ?? entry.key}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {entry.tapped
                                                ? 'Face-down — does nothing when resolved.'
                                                : card?.action}
                                        </p>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex gap-4 rounded-md border bg-muted/40 p-3">
                        <p>
                            <span className="font-medium">Draw pile:</span>{' '}
                            {golem.draw_pile_count}
                        </p>
                        <p>
                            <span className="font-medium">Discard pile:</span>{' '}
                            {golem.discard_pile_count}
                        </p>
                    </div>

                    <div>
                        <p className="mb-2 font-medium">Glyphs</p>
                        {golem.glyphs.length === 0 && (
                            <p className="text-muted-foreground">
                                No active Glyphs.
                            </p>
                        )}
                        <div className="flex flex-col gap-2">
                            {golem.glyphs.map((key, i) => (
                                <div
                                    key={`${key}-${i}`}
                                    className="rounded-md border p-2"
                                >
                                    <p className="font-medium">
                                        {glyphCatalog[key]?.type}{' '}
                                        {glyphCatalog[key]?.name ?? key}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {glyphCatalog[key]?.description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
