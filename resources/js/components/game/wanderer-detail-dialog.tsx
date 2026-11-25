import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import type { EquipSlot, RelicCatalogEntry, WandererState } from '@/types/game';
import { HealthBar } from './health-bar';
import { RoleAvatar } from './role-avatar';

const SLOT_LABELS: Record<EquipSlot, string> = {
    body: '🥋 Body',
    mind: '🧠 Mind',
    hand_left: '🖐️ Hand',
    hand_right: '🖐️ Hand',
    bag: '👝 Bag',
};

// Burn is a status marker, not a Relic — the engine stores it in an
// equip slot, so this has to special-case this sentinel key rather
// than looking it up in the Relic catalog. Shame is a plain counter
// now (WandererState.shame), shown separately below.
const STATUS_TOKEN_LABELS: Record<string, string> = {
    burn_token: '🔥 Burn token',
};

function RelicRow({
    relicKey,
    entry,
    isBurned = false,
}: {
    relicKey: string;
    entry: RelicCatalogEntry | undefined;
    isBurned?: boolean;
}) {
    return (
        <div className="min-w-0 rounded-md border p-2">
            <p className="font-medium break-words">
                {entry ? `${entry.slot_emoji} ${entry.name}` : relicKey}
                {entry?.is_bound && (
                    <span className="ml-2 text-[10px] font-normal text-muted-foreground">
                        🪡 Bound
                    </span>
                )}
                {isBurned && (
                    <span className="ml-2 text-[10px] font-normal text-muted-foreground">
                        {STATUS_TOKEN_LABELS.burn_token}
                    </span>
                )}
            </p>
            {entry?.effect && (
                <p className="break-words text-muted-foreground">
                    {entry.effect}
                </p>
            )}
        </div>
    );
}

export function WandererDetailDialog({
    open,
    onOpenChange,
    role,
    wanderer,
    relicCatalog,
    canAct,
    processing,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: 'wanderer_a' | 'wanderer_b' | 'wanderer_c';
    wanderer: WandererState;
    relicCatalog: Record<string, RelicCatalogEntry>;
    canAct: boolean;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    const label = `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
    // Burn is tracked independently of `equipped` now — Calloused/
    // Inflamed will lets a slot be Burned AND hold a Relic at once, so
    // a burned-but-empty slot needs its own row rather than relying on
    // a sentinel value inside `equipped`.
    const equippedEntries = (Object.keys(SLOT_LABELS) as EquipSlot[])
        .map((slot) => ({
            slot,
            relicKey: wanderer.equipped[slot],
            isBurned: wanderer.burned_slots.includes(slot),
        }))
        .filter((e) => e.relicKey || e.isBurned);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-2xl">
                        <RoleAvatar role={role} size="lg" />
                        {label}
                    </DialogTitle>
                    <DialogDescription>
                        {wanderer.actions_remaining} action
                        {wanderer.actions_remaining === 1 ? '' : 's'} remaining
                        this turn
                    </DialogDescription>
                </DialogHeader>

                <div className="min-w-0 space-y-4 text-sm">
                    <HealthBar hp={wanderer.hp} />
                    {wanderer.shame > 0 && (
                        <p className="text-muted-foreground">
                            😳 Shame x{wanderer.shame}
                        </p>
                    )}

                    {canAct && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={
                                processing ||
                                wanderer.hp >= 15 ||
                                wanderer.actions_remaining <= 0
                            }
                            onClick={() => onSubmit({ type: 'rest' })}
                        >
                            🌿 Rest (+1 Health)
                        </Button>
                    )}

                    <div>
                        <p className="mb-2 font-medium">Equipped Relics</p>
                        {equippedEntries.length === 0 && (
                            <p className="text-muted-foreground">
                                Nothing equipped.
                            </p>
                        )}
                        <div className="flex flex-col gap-2">
                            {equippedEntries.map(
                                ({ slot, relicKey, isBurned }) => (
                                    <RelicRow
                                        key={slot}
                                        relicKey={
                                            relicKey ??
                                            STATUS_TOKEN_LABELS.burn_token
                                        }
                                        entry={
                                            relicKey
                                                ? relicCatalog[relicKey]
                                                : undefined
                                        }
                                        isBurned={isBurned && !!relicKey}
                                    />
                                ),
                            )}
                        </div>
                    </div>

                    {wanderer.holding.length > 0 && (
                        <div>
                            <p className="mb-2 font-medium">
                                Holding (not yet equipped)
                            </p>
                            <div className="flex flex-col gap-2">
                                {wanderer.holding.map((relicKey) => (
                                    <RelicRow
                                        key={relicKey}
                                        relicKey={relicKey}
                                        entry={relicCatalog[relicKey]}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
