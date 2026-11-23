import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { RelicCatalogEntry } from '@/types/game';

/**
 * Generic "pick Relic(s) from a list" prompt — reused by every Relic
 * whose choice is which Relic(s) to act on rather than a target role
 * or a cell: Smoldering (own equipped, any number), Divine Cabalist
 * pyre (a board space's face-up pile, any number), Armistice
 * (any Wanderer's equipped sharing the Golem's space, any number),
 * Warlock's robes/Cabalist brew (exactly one, someone else's).
 * Callers pass in exactly the candidate {key, label} list that
 * Relic's own condition allows.
 */
export function RelicPickerDialog({
    open,
    title,
    description,
    candidates,
    relicCatalog,
    multiple = false,
    processing,
    onConfirm,
    onCancel,
    confirmLabel = 'Confirm',
}: {
    open: boolean;
    title: string;
    description?: string;
    candidates: { key: string; label: string }[];
    relicCatalog: Record<string, RelicCatalogEntry>;
    multiple?: boolean;
    processing: boolean;
    onConfirm: (keys: string[]) => void;
    onCancel?: () => void;
    confirmLabel?: string;
}) {
    const [chosen, setChosen] = useState<Set<number>>(new Set());

    function reset() {
        setChosen(new Set());
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                    onCancel?.();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="min-w-0">
                    <DialogTitle className="break-words">{title}</DialogTitle>
                    {description && (
                        <DialogDescription className="break-words">
                            {description}
                        </DialogDescription>
                    )}
                </DialogHeader>

                {candidates.length === 0 ? (
                    <p className="min-w-0 text-sm break-words text-muted-foreground">
                        Nothing eligible right now.
                    </p>
                ) : (
                    <div className="min-w-0 space-y-2">
                        {candidates.map(({ key, label }, i) => {
                            const entry = relicCatalog[key];
                            const selected = chosen.has(i);
                            return (
                                <label
                                    key={`${key}-${i}`}
                                    className="flex items-center gap-2 rounded-md border p-2 text-sm"
                                >
                                    {multiple ? (
                                        <Checkbox
                                            checked={selected}
                                            onCheckedChange={(checked) => {
                                                setChosen((prev) => {
                                                    const next = new Set(prev);
                                                    if (checked) {
                                                        next.add(i);
                                                    } else {
                                                        next.delete(i);
                                                    }
                                                    return next;
                                                });
                                            }}
                                        />
                                    ) : (
                                        <input
                                            type="radio"
                                            checked={selected}
                                            onChange={() =>
                                                setChosen(new Set([i]))
                                            }
                                        />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        {label}
                                        {entry?.effect && (
                                            <span className="block text-xs text-muted-foreground">
                                                {entry.effect}
                                            </span>
                                        )}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    {onCancel && (
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={processing}
                            onClick={() => {
                                reset();
                                onCancel();
                            }}
                        >
                            Cancel
                        </Button>
                    )}
                    <Button
                        size="sm"
                        disabled={
                            processing ||
                            (!multiple && chosen.size === 0) ||
                            candidates.length === 0
                        }
                        onClick={() => {
                            const keys = candidates
                                .filter((_, i) => chosen.has(i))
                                .map((c) => c.key);
                            reset();
                            onConfirm(keys);
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
