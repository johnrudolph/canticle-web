import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { EquipSlot, RelicCatalogEntry, WandererState } from '@/types/game';
import { HealthBar } from './health-bar';
import { RoleAvatar } from './role-avatar';
import { WandererDetailDialog } from './wanderer-detail-dialog';

// "Use" always submits with no extra input. Relics whose engine behavior
// needs more (e.g. Artist's insight's `directions` payload for its 🦶2)
// aren't reachable from this UI yet — the server still executes them
// correctly, there's just no board-click flow wired up to supply the
// extra input.

const SLOT_LABELS: Record<EquipSlot, string> = {
    body: '🥋 Body',
    mind: '🧠 Mind',
    hand_left: '🖐️ Hand',
    hand_right: '🖐️ Hand',
    bag: '👝 Bag',
};

const RELIC_SLOT_TO_EQUIP_SLOTS: Record<string, EquipSlot[]> = {
    Body: ['body'],
    Mind: ['mind'],
    Bag: ['bag'],
    Hand: ['hand_left', 'hand_right'],
};

function relicLabel(
    key: string,
    catalog: Record<string, RelicCatalogEntry>,
): string {
    const entry = catalog[key];
    return entry ? `${entry.slot_emoji} ${entry.name}` : key;
}

function DropButton({
    relicKey,
    entry,
    processing,
    onSubmit,
}: {
    relicKey: string;
    entry: RelicCatalogEntry | undefined;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    const bound = entry?.is_bound === true;
    const button = (
        <button
            type="button"
            disabled={processing || bound}
            className="text-muted-foreground hover:text-destructive disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:text-muted-foreground"
            onClick={() =>
                onSubmit({ type: 'drop_relic', relic_key: relicKey })
            }
        >
            drop
        </button>
    );

    if (!bound) return button;

    return (
        <Tooltip>
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            <TooltipContent>
                Bound Relics can't be dropped once equipped — only trashed by
                their own effect.
            </TooltipContent>
        </Tooltip>
    );
}

export function WandererRelicPanel({
    role,
    wanderer,
    relicCatalog,
    processing,
    onSubmit,
    canAct,
    isActive = false,
    allies,
}: {
    role: 'wanderer_a' | 'wanderer_b' | 'wanderer_c';
    wanderer: WandererState;
    relicCatalog: Record<string, RelicCatalogEntry>;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
    /** Whether this Wanderer can spend an action right now (their own active turn). */
    canAct: boolean;
    /** Whether it's this Wanderer's turn right now, regardless of who's viewing. */
    isActive?: boolean;
    /** Other Wanderers sharing this one's space, for Relics that target an Ally. */
    allies: { role: string; label: string }[];
}) {
    const [detailOpen, setDetailOpen] = useState(false);
    const label = `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;

    return (
        <div
            className={`rounded-md border bg-card p-2 text-xs ${
                isActive ? 'border-primary ring-1 ring-primary' : ''
            }`}
        >
            <button
                type="button"
                onClick={() => setDetailOpen(true)}
                className="mb-1.5 flex w-full items-center gap-2 text-left hover:opacity-80"
            >
                <RoleAvatar role={role} size="lg" />
                <div className="flex-1">
                    <p className="font-medium">
                        {label}
                        {isActive && (
                            <span className="ml-2 rounded bg-primary px-1.5 py-0.5 text-[10px] text-primary-foreground">
                                {wanderer.actions_remaining} action
                                {wanderer.actions_remaining === 1
                                    ? ''
                                    : 's'}{' '}
                                left
                            </span>
                        )}
                    </p>
                    <HealthBar hp={wanderer.hp} className="mt-1" />
                </div>
            </button>

            {canAct && (
                <Button
                    size="sm"
                    variant="outline"
                    className="mb-1.5 h-6 w-full px-1.5 text-[10px]"
                    disabled={
                        processing ||
                        wanderer.hp >= 15 ||
                        wanderer.actions_remaining <= 0
                    }
                    onClick={() => onSubmit({ type: 'rest' })}
                >
                    🌿 Rest (+2 Health)
                </Button>
            )}

            <div className="grid grid-cols-2 gap-1">
                {(Object.keys(SLOT_LABELS) as EquipSlot[]).map((slot) => {
                    const relicKey = wanderer.equipped[slot];
                    return (
                        <div key={slot} className="rounded border px-1.5 py-1">
                            <p className="text-muted-foreground">
                                {SLOT_LABELS[slot]}
                            </p>
                            {relicKey ? (
                                <div className="flex flex-col gap-1">
                                    <div className="flex items-center justify-between gap-1">
                                        <span className="truncate">
                                            {relicLabel(relicKey, relicCatalog)}
                                        </span>
                                        <DropButton
                                            relicKey={relicKey}
                                            entry={relicCatalog[relicKey]}
                                            processing={processing}
                                            onSubmit={onSubmit}
                                        />
                                    </div>
                                    {canAct && (
                                        <div className="flex flex-wrap gap-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="h-5 px-1.5 text-[10px]"
                                                disabled={processing}
                                                onClick={() =>
                                                    onSubmit({
                                                        type: 'use_relic',
                                                        relic_key: relicKey,
                                                    })
                                                }
                                            >
                                                Use
                                            </Button>
                                            {allies.map((ally) => (
                                                <Button
                                                    key={ally.role}
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-5 px-1.5 text-[10px]"
                                                    disabled={processing}
                                                    onClick={() =>
                                                        onSubmit({
                                                            type: 'use_relic',
                                                            relic_key: relicKey,
                                                            target_role:
                                                                ally.role,
                                                        })
                                                    }
                                                >
                                                    Use on {ally.label}
                                                </Button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <span className="text-muted-foreground/60">
                                    empty
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>

            {wanderer.holding.length > 0 && (
                <div className="mt-2 space-y-1">
                    <p className="text-muted-foreground">Holding</p>
                    {wanderer.holding.map((relicKey) => {
                        const entry = relicCatalog[relicKey];
                        const equipSlots = entry
                            ? (RELIC_SLOT_TO_EQUIP_SLOTS[entry.slot] ?? [])
                            : [];

                        return (
                            <div
                                key={relicKey}
                                className="flex items-center justify-between gap-1 rounded border px-1.5 py-1"
                            >
                                <span className="truncate">
                                    {relicLabel(relicKey, relicCatalog)}
                                </span>
                                <div className="flex gap-1">
                                    {equipSlots.map((slot) => (
                                        <Button
                                            key={slot}
                                            size="sm"
                                            variant="outline"
                                            className="h-5 px-1.5 text-[10px]"
                                            disabled={processing}
                                            onClick={() =>
                                                onSubmit({
                                                    type: 'equip_relic',
                                                    relic_key: relicKey,
                                                    slot,
                                                })
                                            }
                                        >
                                            Equip {SLOT_LABELS[slot]}
                                        </Button>
                                    ))}
                                    <button
                                        type="button"
                                        disabled={processing}
                                        className="text-muted-foreground hover:text-destructive"
                                        onClick={() =>
                                            onSubmit({
                                                type: 'drop_relic',
                                                relic_key: relicKey,
                                            })
                                        }
                                    >
                                        drop
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

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
        </div>
    );
}
