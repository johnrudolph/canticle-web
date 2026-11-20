import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BoardCell, LocationCatalogEntry } from '@/types/game';

/**
 * Epiphany's choice can't be supplied in the same move that found it
 * (same reason as Invigoration/Rift in time), so it blocks on a
 * dedicated choice instead of resolving on the spot. Only candidates
 * are built Locations other than the caster's own space that still
 * have at least one face-down Relic to pull from — if none qualify,
 * there's nothing to choose, so it offers a dismiss instead.
 */
export function EpiphanyDialog({
    open,
    candidates,
    locationCatalog,
    processing,
    onChoose,
}: {
    open: boolean;
    candidates: {
        cell: [number, number];
        cellKey: string;
        cellData: BoardCell;
    }[];
    locationCatalog: Record<string, LocationCatalogEntry>;
    processing: boolean;
    onChoose: (cell: [number, number] | null) => void;
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
                        ✨ Epiphany
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        Gain a face-down Relic on a different Location you
                        choose.
                    </DialogDescription>
                </DialogHeader>

                {candidates.length === 0 ? (
                    <p className="min-w-0 text-sm break-words text-muted-foreground">
                        No other Location has a face-down Relic left to find.
                    </p>
                ) : (
                    <div className="min-w-0 space-y-2">
                        {candidates.map(({ cell, cellKey, cellData }) => {
                            const loc = locationCatalog[cellData.location_key];
                            return (
                                <div
                                    key={cellKey}
                                    className="flex items-center justify-between gap-3 rounded-md border p-2 text-sm"
                                >
                                    <p className="min-w-0 truncate font-medium">
                                        {loc?.produces_emoji}{' '}
                                        {loc?.name ?? cellData.location_key}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            ({cellData.relics.length} face-down)
                                        </span>
                                    </p>
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => onChoose(cell)}
                                    >
                                        Search
                                    </Button>
                                </div>
                            );
                        })}
                    </div>
                )}

                {candidates.length === 0 && (
                    <DialogFooter>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() => onChoose(null)}
                        >
                            Continue
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
