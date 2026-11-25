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

export function NewRelicDialog({
    open,
    relicKey,
    entry,
    equipped,
    burnedSlots = [],
    hasCalloused = false,
    relicCatalog = {},
    processing,
    onSubmit,
}: {
    open: boolean;
    relicKey: string | null;
    entry: RelicCatalogEntry | undefined;
    equipped?: Record<EquipSlot, string | null> | null;
    /** Which slots are Burned — tracked independently of `equipped` now, since Calloused/Inflamed will lets a slot be Burned AND hold a Relic at once. */
    burnedSlots?: string[];
    /** Calloused/Inflamed will: "You may equip relics in slots where you have Scars" — lifts the Burn block for its own holder. */
    hasCalloused?: boolean;
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
                <DialogHeader className="min-w-0">
                    <DialogTitle className="text-2xl break-words">
                        ✨ You found{' '}
                        {entry ? `${entry.slot_emoji} ${entry.name}` : relicKey}
                        !
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        {entry?.is_bound
                            ? 'Bound — you must equip this (unless every eligible slot is blocked by a Burn token or another Bound Relic). It can never be dropped, tucked, trashed, or traded once equipped.'
                            : 'Equip it now (this drops whatever is in that slot), or drop it face-up here instead.'}
                    </DialogDescription>
                </DialogHeader>

                {entry?.effect && (
                    <p className="min-w-0 rounded-md border bg-muted/40 p-3 text-sm break-words">
                        {entry.effect}
                    </p>
                )}

                <div className="min-w-0 space-y-2">
                    {equipSlots.map((slot) => {
                        const current = equipped?.[slot] ?? null;
                        const currentEntry = current
                            ? relicCatalog[current]
                            : undefined;
                        const isBurned = burnedSlots.includes(slot);
                        const blockedByBurn = isBurned && !hasCalloused;
                        const blockedByBound =
                            current !== null && currentEntry?.is_bound === true;

                        return (
                            <div
                                key={slot}
                                className="flex items-center justify-between gap-3 rounded-md border p-2 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {SLOT_LABELS[slot]}
                                    </p>
                                    <p className="min-w-0 text-xs break-words text-muted-foreground">
                                        {blockedByBurn &&
                                            'Blocked by a Burn token.'}
                                        {!blockedByBurn &&
                                            blockedByBound &&
                                            `Blocked by a Bound Relic (${currentEntry?.name ?? current}).`}
                                        {!blockedByBurn &&
                                            !blockedByBound &&
                                            current === null &&
                                            'Currently empty.'}
                                        {!blockedByBurn &&
                                            !blockedByBound &&
                                            current !== null &&
                                            `Currently: ${currentEntry?.name ?? current}${
                                                currentEntry?.effect
                                                    ? ` — ${currentEntry.effect}`
                                                    : ''
                                            }`}
                                        {isBurned &&
                                            hasCalloused &&
                                            ' (Burned, but Inflamed will lets you equip anyway.)'}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    disabled={
                                        processing ||
                                        blockedByBurn ||
                                        blockedByBound
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

                {!entry?.is_bound && (
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
                )}
            </DialogContent>
        </Dialog>
    );
}
