import type { GlyphCatalogEntry, GolemCardCatalogEntry, GolemState } from '@/types/game';

export function GolemPanel({
    golem,
    golemCardCatalog,
    glyphCatalog,
    golemCardsThisRound,
}: {
    golem: GolemState;
    golemCardCatalog: Record<string, GolemCardCatalogEntry>;
    glyphCatalog: Record<string, GlyphCatalogEntry>;
    golemCardsThisRound: number;
}) {
    return (
        <div className="rounded-md border bg-card p-2 text-xs">
            <p className="mb-1 font-medium">
                👺 Golem — plays {golemCardsThisRound} cards this round
            </p>
            <p className="text-muted-foreground">
                Shame: {golem.shame} · Draw pile: {golem.draw_pile_count}
            </p>

            <p className="mt-2 text-muted-foreground">Upcoming cards (right resolves next)</p>
            <div className="flex flex-wrap gap-1">
                {golem.revealed.length === 0 && (
                    <span className="text-muted-foreground/60">none</span>
                )}
                {golem.revealed.map((key, i) => (
                    <span key={`${key}-${i}`} className="rounded border px-1.5 py-0.5">
                        {golemCardCatalog[key]?.name ?? key}
                    </span>
                ))}
            </div>

            {golem.glyphs.length > 0 && (
                <>
                    <p className="mt-2 text-muted-foreground">Glyphs</p>
                    <div className="flex flex-col gap-1">
                        {golem.glyphs.map((key, i) => (
                            <div key={`${key}-${i}`} className="rounded border px-1.5 py-1">
                                <span className="font-medium">
                                    {glyphCatalog[key]?.type} {glyphCatalog[key]?.name ?? key}
                                </span>
                                <p className="text-muted-foreground">
                                    {glyphCatalog[key]?.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
