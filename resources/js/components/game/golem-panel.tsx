import { useState } from 'react';
import type {
    GlyphCatalogEntry,
    GolemCardCatalogEntry,
    GolemState,
} from '@/types/game';
import { GolemDetailDialog } from './golem-detail-dialog';
import { RoleAvatar } from './role-avatar';

export function GolemPanel({
    golem,
    golemCardCatalog,
    glyphCatalog,
    golemCardsThisRound,
    isActive = false,
}: {
    golem: GolemState;
    golemCardCatalog: Record<string, GolemCardCatalogEntry>;
    glyphCatalog: Record<string, GlyphCatalogEntry>;
    golemCardsThisRound: number;
    isActive?: boolean;
}) {
    const [detailOpen, setDetailOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setDetailOpen(true)}
                className={`w-full rounded-md border p-2 text-left text-xs transition-colors hover:bg-accent/40 ${
                    isActive ? 'border-primary ring-1 ring-primary' : ''
                }`}
            >
                <div className="mb-1 flex items-center gap-2">
                    <RoleAvatar role="golem" size="lg" />
                    <div>
                        <p className="font-medium">
                            Golem — plays {golemCardsThisRound} cards this round
                        </p>
                        <p className="text-muted-foreground">
                            Shame: {golem.shame} · Draw: {golem.draw_pile_count}{' '}
                            · Discard: {golem.discard_pile_count}
                        </p>
                    </div>
                </div>

                <p className="mt-2 text-muted-foreground">
                    Upcoming cards (right resolves next)
                </p>
                <div className="flex gap-1 overflow-x-auto pb-1">
                    {golem.revealed.length === 0 && (
                        <span className="text-muted-foreground/60">none</span>
                    )}
                    {golem.revealed.map((entry, i) => {
                        const card = golemCardCatalog[entry.key];
                        return (
                            <div
                                key={`${entry.key}-${i}`}
                                className={`shrink-0 rounded border px-2 py-1 whitespace-nowrap ${
                                    entry.tapped ? 'opacity-50' : ''
                                }`}
                            >
                                <p className="font-medium">
                                    {entry.tapped ? '🂠 ' : ''}
                                    {card?.name ?? entry.key}
                                </p>
                                {!entry.tapped && (
                                    <p className="max-w-48 truncate text-muted-foreground">
                                        {card?.action}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>

                {golem.glyphs.length > 0 && (
                    <>
                        <p className="mt-2 text-muted-foreground">Glyphs</p>
                        <div className="flex flex-wrap gap-1">
                            {golem.glyphs.map((key, i) => (
                                <span
                                    key={`${key}-${i}`}
                                    className="rounded border px-1.5 py-0.5"
                                >
                                    {glyphCatalog[key]?.type}{' '}
                                    {glyphCatalog[key]?.name ?? key}
                                </span>
                            ))}
                        </div>
                    </>
                )}
            </button>

            <GolemDetailDialog
                open={detailOpen}
                onOpenChange={setDetailOpen}
                golem={golem}
                golemCardCatalog={golemCardCatalog}
                glyphCatalog={glyphCatalog}
                golemCardsThisRound={golemCardsThisRound}
            />
        </>
    );
}
