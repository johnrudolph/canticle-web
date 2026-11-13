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
    onOpenChange,
    relicKey,
    entry,
    processing,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    relicKey: string | null;
    entry: RelicCatalogEntry | undefined;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    if (!relicKey) return null;

    const equipSlots = entry
        ? (RELIC_SLOT_TO_EQUIP_SLOTS[entry.slot] ?? [])
        : [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        ✨ You found{' '}
                        {entry ? `${entry.slot_emoji} ${entry.name}` : relicKey}
                        !
                    </DialogTitle>
                    <DialogDescription>
                        {entry?.is_bound &&
                            'Bound — once equipped, this can only be removed by trashing. '}
                        Equip it now, or drop it face-up here for someone else.
                    </DialogDescription>
                </DialogHeader>

                {entry?.effect && (
                    <p className="rounded-md border bg-muted/40 p-3 text-sm">
                        {entry.effect}
                    </p>
                )}

                <DialogFooter className="flex-wrap sm:justify-start">
                    {equipSlots.map((slot) => (
                        <Button
                            key={slot}
                            size="sm"
                            disabled={processing}
                            onClick={() => {
                                onSubmit({
                                    type: 'equip_relic',
                                    relic_key: relicKey,
                                    slot,
                                });
                                onOpenChange(false);
                            }}
                        >
                            Equip to {SLOT_LABELS[slot]}
                        </Button>
                    ))}
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() => {
                            onSubmit({
                                type: 'drop_relic',
                                relic_key: relicKey,
                            });
                            onOpenChange(false);
                        }}
                    >
                        Drop it face-up
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Decide later
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
