import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    INFRASTRUCTURE_DESCRIPTIONS,
    INFRASTRUCTURE_LABELS,
} from '@/lib/infrastructure';
import type {
    BoardCell,
    InfrastructureType,
    LocationCatalogEntry,
    RelicCatalogEntry,
} from '@/types/game';
import { RoleAvatar } from './role-avatar';

function wandererLabel(role: string): string {
    return `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
}

export function LocationDetailDialog({
    open,
    onOpenChange,
    location,
    cellData,
    relicCatalog = {},
    wanderersHere = [],
    golemHere = false,
    myRoleHere = null,
    onPickupRelic,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    location: LocationCatalogEntry | undefined;
    cellData: BoardCell | undefined;
    relicCatalog?: Record<string, RelicCatalogEntry>;
    wanderersHere?: string[];
    golemHere?: boolean;
    myRoleHere?: string | null;
    onPickupRelic?: (role: string, relicKey: string) => void;
}) {
    if (!location) return null;

    const faceDownCount = cellData?.relics.length ?? 0;
    const faceUpRelics = cellData?.relics_face_up ?? [];

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
                            <div className="space-y-1.5">
                                {[...new Set(cellData.infrastructure)].map(
                                    (type) => (
                                        <p key={type}>
                                            <span className="font-medium">
                                                {INFRASTRUCTURE_LABELS[
                                                    type as InfrastructureType
                                                ] ?? type}
                                                :
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                {
                                                    INFRASTRUCTURE_DESCRIPTIONS[
                                                        type as InfrastructureType
                                                    ]
                                                }
                                            </span>
                                        </p>
                                    ),
                                )}
                            </div>
                        )}
                    </div>

                    <div className="rounded-md border p-3">
                        <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Relics
                        </p>

                        <p className="text-muted-foreground">
                            Deals {location.relic_count} face-down Relic
                            {location.relic_count === 1 ? '' : 's'} when built —{' '}
                            {faceDownCount} still face-down here.
                        </p>

                        {faceUpRelics.length > 0 && (
                            <div className="mt-2 space-y-2">
                                {faceUpRelics.map((relicKey, i) => {
                                    const entry = relicCatalog[relicKey];

                                    return (
                                        <div
                                            key={`${relicKey}-${i}`}
                                            className="rounded-md border bg-muted/40 p-2"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="font-medium">
                                                    {entry?.symbol}{' '}
                                                    {entry?.name ?? relicKey}
                                                </p>
                                                {myRoleHere &&
                                                    onPickupRelic && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-6 px-2 text-xs"
                                                            onClick={() =>
                                                                onPickupRelic(
                                                                    myRoleHere,
                                                                    relicKey,
                                                                )
                                                            }
                                                        >
                                                            Pick up
                                                        </Button>
                                                    )}
                                            </div>
                                            {entry?.effect && (
                                                <p className="mt-1 text-muted-foreground">
                                                    {entry.effect}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
