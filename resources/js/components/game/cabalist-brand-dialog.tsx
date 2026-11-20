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
import type { GlyphCatalogEntry } from '@/types/game';

/**
 * Cabalist brand: "discard any Glyphs on its mat you choose" — the
 * generic "Use" button submits with no extra input, so this choice
 * (which Glyphs, if any) needs its own dialog rather than firing
 * use_relic directly.
 */
export function CabalistBrandDialog({
    open,
    activeGlyphs,
    glyphCatalog,
    processing,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    activeGlyphs: string[];
    glyphCatalog: Record<string, GlyphCatalogEntry>;
    processing: boolean;
    onConfirm: (glyphKeys: string[]) => void;
    onCancel: () => void;
}) {
    const [chosen, setChosen] = useState<Set<string>>(new Set());

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setChosen(new Set());
                    onCancel();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="min-w-0">
                    <DialogTitle className="text-2xl break-words">
                        🌑 Cabalist brand
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        Choose any Glyphs on the Golem's mat to discard
                        (optional), then the Golem plays 3 cards.
                    </DialogDescription>
                </DialogHeader>

                {activeGlyphs.length === 0 ? (
                    <p className="min-w-0 text-sm break-words text-muted-foreground">
                        No Glyphs on the Golem's mat right now.
                    </p>
                ) : (
                    <div className="min-w-0 space-y-2">
                        {activeGlyphs.map((key, i) => {
                            const entry = glyphCatalog[key];
                            const id = `${key}-${i}`;
                            return (
                                <label
                                    key={id}
                                    className="flex items-center gap-2 rounded-md border p-2 text-sm"
                                >
                                    <Checkbox
                                        checked={chosen.has(id)}
                                        onCheckedChange={(checked) => {
                                            setChosen((prev) => {
                                                const next = new Set(prev);
                                                if (checked) {
                                                    next.add(id);
                                                } else {
                                                    next.delete(id);
                                                }
                                                return next;
                                            });
                                        }}
                                    />
                                    <span className="min-w-0">
                                        {entry?.name ?? key}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={() => {
                            const glyphKeys = activeGlyphs.filter((key, i) =>
                                chosen.has(`${key}-${i}`),
                            );
                            setChosen(new Set());
                            onConfirm(glyphKeys);
                        }}
                    >
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
