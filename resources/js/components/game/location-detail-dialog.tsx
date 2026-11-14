import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BoardCell, LocationCatalogEntry } from '@/types/game';
import { RoleAvatar } from './role-avatar';

const INFRASTRUCTURE_LABELS: Record<string, string> = {
    monolith_shard: '✨ Monolith Shard',
    road: '🧱 Road',
    walls: '🏰 Walls',
    fruit_tree: '🌳 Fruit tree',
};

function wandererLabel(role: string): string {
    return `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
}

export function LocationDetailDialog({
    open,
    onOpenChange,
    location,
    cellData,
    wanderersHere = [],
    golemHere = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    location: LocationCatalogEntry | undefined;
    cellData: BoardCell | undefined;
    wanderersHere?: string[];
    golemHere?: boolean;
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

                    <div className="rounded-md border p-3">
                        <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Here right now
                        </p>

                        {wanderersHere.length === 0 && !golemHere && (
                            <p className="text-muted-foreground">Nobody.</p>
                        )}

                        {(wanderersHere.length > 0 || golemHere) && (
                            <div className="mb-2 flex flex-wrap gap-3">
                                {golemHere && (
                                    <div className="flex items-center gap-1.5">
                                        <RoleAvatar role="golem" />
                                        <span>Golem</span>
                                    </div>
                                )}
                                {wanderersHere.map((role) => (
                                    <div
                                        key={role}
                                        className="flex items-center gap-1.5"
                                    >
                                        <RoleAvatar
                                            role={
                                                role as
                                                    | 'wanderer_a'
                                                    | 'wanderer_b'
                                                    | 'wanderer_c'
                                            }
                                        />
                                        <span>{wandererLabel(role)}</span>
                                    </div>
                                ))}
                            </div>
                        )}

                        {cellData && cellData.infrastructure.length > 0 && (
                            <p>
                                {[...new Set(cellData.infrastructure)]
                                    .map(
                                        (type) =>
                                            INFRASTRUCTURE_LABELS[type] ?? type,
                                    )
                                    .join(' · ')}
                            </p>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
