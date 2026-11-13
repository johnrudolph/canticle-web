import { Button } from '@/components/ui/button';
import type { EquipSlot, RelicCatalogEntry, WandererState } from '@/types/game';

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

function relicLabel(key: string, catalog: Record<string, RelicCatalogEntry>): string {
    const entry = catalog[key];
    return entry ? `${entry.slot_emoji} ${entry.name}` : key;
}

export function WandererRelicPanel({
    role,
    wanderer,
    relicCatalog,
    processing,
    onSubmit,
}: {
    role: string;
    wanderer: WandererState;
    relicCatalog: Record<string, RelicCatalogEntry>;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    const label = `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;

    return (
        <div className="rounded-md border bg-card p-2 text-xs">
            <p className="mb-1 font-medium">
                {label} — {wanderer.hp} HP
            </p>
            <div className="grid grid-cols-2 gap-1">
                {(Object.keys(SLOT_LABELS) as EquipSlot[]).map((slot) => {
                    const relicKey = wanderer.equipped[slot];
                    return (
                        <div key={slot} className="rounded border px-1.5 py-1">
                            <p className="text-muted-foreground">{SLOT_LABELS[slot]}</p>
                            {relicKey ? (
                                <div className="flex items-center justify-between gap-1">
                                    <span className="truncate">
                                        {relicLabel(relicKey, relicCatalog)}
                                    </span>
                                    <button
                                        type="button"
                                        disabled={processing}
                                        className="text-muted-foreground hover:text-destructive"
                                        onClick={() =>
                                            onSubmit({ type: 'drop_relic', relic_key: relicKey })
                                        }
                                    >
                                        drop
                                    </button>
                                </div>
                            ) : (
                                <span className="text-muted-foreground/60">empty</span>
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
                        const equipSlots = entry ? (RELIC_SLOT_TO_EQUIP_SLOTS[entry.slot] ?? []) : [];

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
                                                onSubmit({ type: 'equip_relic', relic_key: relicKey, slot })
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
                                            onSubmit({ type: 'drop_relic', relic_key: relicKey })
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
        </div>
    );
}
