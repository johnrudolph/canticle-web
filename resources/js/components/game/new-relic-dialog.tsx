import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { EquipSlot, RelicCatalogEntry } from '@/types/game';

const SLOT_LABELS: Record<EquipSlot, string> = {
    body: '🥋 Body',
    mind: '🧠 Mind',
    hand_left: '🖐️ Hand (left)',
    hand_right: '🖐️ Hand (right)',
    bag: '👝 Bag',
};

const RELIC_SLOT_TO_EQUIP_SLOTS: Record<string, EquipSlot[]> = {
    Body: ['body'],
    Mind: ['mind'],
    Bag: ['bag'],
    Hand: ['hand_left', 'hand_right'],
};

function isStatusToken(
    value: string | null,
): value is 'shame_token' | 'scar_token' {
    return value === 'shame_token' || value === 'scar_token';
}

export function NewRelicDialog({
    open,
    relicKey,
    entry,
    equipped,
    relicCatalog = {},
    processing,
    onSubmit,
}: {
    open: boolean;
    relicKey: string | null;
    entry: RelicCatalogEntry | undefined;
    equipped?: Record<EquipSlot, string | null> | null;
    relicCatalog?: Record<string, RelicCatalogEntry>;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    if (!relicKey) return null;

    const equipSlots = entry
        ? (RELIC_SLOT_TO_EQUIP_SLOTS[entry.slot] ?? [])
        : [];

    return (
        // No onOpenChange — there's no "decide later." The parent fully
        // controls `open`, closing this only once polled state confirms
        // the Relic actually left `holding` (the equip/drop genuinely
        // succeeded). Escape/backdrop/the built-in X button are disabled
        // below; without that, any of them would close this optimistically
        // even when the submit fails server-side, leaving the Relic stuck
        // in holding with no further prompt — exactly the bug reported in
        // game CYTGGM (a failed equip attempt left Artist's insight in
        // limbo with no way back to this dialog).
        <Dialog open={open} onOpenChange={() => {}}>
            <DialogContent
                className="sm:max-w-lg [&>button]:hidden"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onPointerDownOutside={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        ✨ You found{' '}
                        {entry ? `${entry.slot_emoji} ${entry.name}` : relicKey}
                        !
                    </DialogTitle>
                    <DialogDescription>
                        {entry?.is_bound &&
                            'Bound — once equipped, this can only be removed by trashing. '}
                        Equip it now (this drops whatever is in that slot), or
                        drop it face-up here instead.
                    </DialogDescription>
                </DialogHeader>

                {entry?.effect && (
                    <p className="rounded-md border bg-muted/40 p-3 text-sm">
                        {entry.effect}
                    </p>
                )}

                <div className="space-y-2">
                    {equipSlots.map((slot) => {
                        const current = equipped?.[slot] ?? null;
                        const currentEntry = current
                            ? relicCatalog[current]
                            : undefined;

                        return (
                            <div
                                key={slot}
                                className="flex items-center justify-between gap-3 rounded-md border p-2 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {SLOT_LABELS[slot]}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {current === null && 'Currently empty.'}
                                        {isStatusToken(current) &&
                                            `Blocked by a ${current === 'shame_token' ? 'Shame' : 'Scar'} token.`}
                                        {current !== null &&
                                            !isStatusToken(current) &&
                                            `Currently: ${currentEntry?.name ?? current}${
                                                currentEntry?.effect
                                                    ? ` — ${currentEntry.effect}`
                                                    : ''
                                            }`}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    disabled={
                                        processing || isStatusToken(current)
                                    }
                                    onClick={() =>
                                        onSubmit({
                                            type: 'equip_relic',
                                            relic_key: relicKey,
                                            slot,
                                        })
                                    }
                                >
                                    Equip
                                </Button>
                            </div>
                        );
                    })}
                </div>

                <DialogFooter className="flex-wrap sm:justify-start">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            onSubmit({
                                type: 'drop_relic',
                                relic_key: relicKey,
                            })
                        }
                    >
                        Drop it face-up
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
