import { useState } from 'react';
import type { RelicCatalogEntry, WandererState } from '@/types/game';
import { HealthBar } from './health-bar';
import { RoleAvatar } from './role-avatar';
import { WandererDetailDialog } from './wanderer-detail-dialog';

/**
 * The concise form of a Wanderer's panel — just enough to track at a
 * glance (avatar, name, Health) with a click-through to the full
 * spotlight for everything else. Used for every Wanderer except whoever
 * is actually taking their turn right now, which gets the full
 * WandererRelicPanel instead.
 */
export function WandererSummaryRow({
    role,
    wanderer,
    relicCatalog,
    canAct,
    processing,
    onSubmit,
}: {
    role: 'wanderer_a' | 'wanderer_b' | 'wanderer_c';
    wanderer: WandererState;
    relicCatalog: Record<string, RelicCatalogEntry>;
    canAct: boolean;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    const [detailOpen, setDetailOpen] = useState(false);
    const label = `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
    const equippedCount = Object.values(wanderer.equipped).filter(
        (v) => v !== null,
    ).length;

    return (
        <>
            <button
                type="button"
                onClick={() => setDetailOpen(true)}
                className="flex w-full items-center gap-2 rounded-md border bg-card p-2 text-left text-xs hover:bg-muted/40"
            >
                <RoleAvatar role={role} size="lg" />
                <div className="flex-1">
                    <p className="font-medium">{label}</p>
                    <HealthBar hp={wanderer.hp} className="mt-1" />
                </div>
                <span className="text-muted-foreground">
                    {equippedCount}/5 equipped
                    {wanderer.holding.length > 0 &&
                        ` · ${wanderer.holding.length} held`}
                </span>
            </button>

            <WandererDetailDialog
                open={detailOpen}
                onOpenChange={setDetailOpen}
                role={role}
                wanderer={wanderer}
                relicCatalog={relicCatalog}
                canAct={canAct}
                processing={processing}
                onSubmit={onSubmit}
            />
        </>
    );
}
