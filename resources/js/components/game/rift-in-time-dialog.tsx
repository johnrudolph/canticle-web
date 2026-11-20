import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GlyphCatalogEntry } from '@/types/game';

/**
 * Rift in time's choice can't be supplied in the same move that found
 * it (the Wanderer doesn't know they found it until that move already
 * returns), so — like Invigoration — it blocks on a dedicated choice
 * instead of resolving on the spot. Only offered when there's at
 * least one face-up Glyph to choose from, so there's always a real
 * choice here, no "skip" option.
 */
export function RiftInTimeDialog({
    open,
    activeGlyphs,
    glyphCatalog,
    processing,
    onChoose,
}: {
    open: boolean;
    activeGlyphs: string[];
    glyphCatalog: Record<string, GlyphCatalogEntry>;
    processing: boolean;
    onChoose: (glyphKey: string) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={() => {}}>
            <DialogContent
                className="sm:max-w-md [&>button]:hidden"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onPointerDownOutside={(e) => e.preventDefault()}
            >
                <DialogHeader className="min-w-0">
                    <DialogTitle className="text-2xl break-words">
                        ⏳ Rift in time
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        Choose a face-up Golem Glyph to discard and replace with
                        another of the same type.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-w-0 space-y-2">
                    {activeGlyphs.map((key, i) => {
                        const entry = glyphCatalog[key];
                        return (
                            <div
                                key={`${key}-${i}`}
                                className="flex items-center justify-between gap-3 rounded-md border p-2 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {entry?.name ?? key}
                                    </p>
                                    {entry?.description && (
                                        <p className="min-w-0 text-xs break-words text-muted-foreground">
                                            {entry.description}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    size="sm"
                                    disabled={processing}
                                    onClick={() => onChoose(key)}
                                >
                                    Replace
                                </Button>
                            </div>
                        );
                    })}
                </div>
            </DialogContent>
        </Dialog>
    );
}
