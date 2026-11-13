import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BoardCell, LocationCatalogEntry } from '@/types/game';

export function LocationDetailDialog({
    open,
    onOpenChange,
    location,
    cellData,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    location: LocationCatalogEntry | undefined;
    cellData: BoardCell | undefined;
}) {
    if (!location) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="text-2xl">
                        {location.produces_emoji} {location.name}
                    </DialogTitle>
                    {location.produces && (
                        <DialogDescription>
                            Produces {location.produces}
                        </DialogDescription>
                    )}
                </DialogHeader>

                <div className="space-y-4 text-sm">
                    {location.lore && (
                        <p className="text-muted-foreground italic">
                            {location.lore}
                        </p>
                    )}

                    {location.back_text && (
                        <div className="rounded-md border bg-muted/40 p-3">
                            <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Rule
                            </p>
                            <p className="whitespace-pre-wrap">
                                {location.back_text}
                            </p>
                        </div>
                    )}

                    {location.costs.length > 0 && (
                        <p>
                            <span className="font-medium">Build cost:</span>{' '}
                            adjacent to {location.costs.join(', ')}
                        </p>
                    )}

                    <p className="text-muted-foreground">
                        Deals {location.relic_count} face-down Relic
                        {location.relic_count === 1 ? '' : 's'} when built —{' '}
                        {cellData?.relics.length ?? 0} still here right now.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
