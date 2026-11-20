import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    INFRASTRUCTURE_DESCRIPTIONS,
    INFRASTRUCTURE_LABELS,
} from '@/lib/infrastructure';
import { tucksGolemCards } from '@/lib/tucking';
import type {
    BoardCell,
    GolemCardCatalogEntry,
    InfrastructureType,
    LocationCatalogEntry,
    RelicCatalogEntry,
} from '@/types/game';
import { RoleAvatar } from './role-avatar';

function wandererLabel(role: string): string {
    return `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
}

/** Branch name (e.g. "Art") -> the emoji whichever Location produces it uses. */
function branchEmoji(
    branch: string,
    locationCatalog: Record<string, LocationCatalogEntry>,
): string | null {
    const match = Object.values(locationCatalog).find(
        (l) => l.produces === branch,
    );
    return match?.produces_emoji ?? null;
}

export function LocationDetailDialog({
    open,
    onOpenChange,
    location,
    cellData,
    locationCatalog = {},
    relicCatalog = {},
    golemCardCatalog = {},
    wanderersHere = [],
    golemHere = false,
    myRoleHere = null,
    onPickupRelic,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    location: LocationCatalogEntry | undefined;
    cellData: BoardCell | undefined;
    locationCatalog?: Record<string, LocationCatalogEntry>;
    relicCatalog?: Record<string, RelicCatalogEntry>;
    golemCardCatalog?: Record<string, GolemCardCatalogEntry>;
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
                </DialogHeader>

                <div className="min-w-0 space-y-4 text-sm">
                    {location.lore && (
                        <p className="text-muted-foreground italic">
                            {location.lore}
                        </p>
                    )}

                    <div className="min-w-0 rounded-md border bg-muted/40 p-3">
                        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Adjacency requirement
                        </p>
                        <p className="min-w-0 break-words">
                            {location.costs.length === 0
                                ? 'None — can be built adjacent to anything.'
                                : `Must be adjacent to a space producing: ${location.costs
                                      .map((c) => {
                                          const emoji = branchEmoji(
                                              c,
                                              locationCatalog,
                                          );
                                          return emoji ? `${emoji} ${c}` : c;
                                      })
                                      .join(', ')}`}
                        </p>
                    </div>

                    {location.back_text && (
                        <div className="min-w-0 rounded-md border bg-muted/40 p-3">
                            <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Rule
                            </p>
                            <p className="min-w-0 break-words whitespace-pre-wrap">
                                {location.back_text}
                            </p>
                        </div>
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
                            {faceDownCount} face-down Relic
                            {faceDownCount === 1 ? '' : 's'} here.
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
                                            <div className="flex min-w-0 items-center justify-between gap-2">
                                                <p className="min-w-0 font-medium break-words">
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
                                                <p className="mt-1 break-words text-muted-foreground">
                                                    {entry.effect}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {cellData && cellData.tucked.length > 0 && (
                        <div className="rounded-md border p-3">
                            <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Tucked ({cellData.tucked.length})
                            </p>

                            <div className="flex flex-wrap gap-1.5">
                                {cellData.tucked.map((key, i) => {
                                    const name = tucksGolemCards(
                                        cellData.location_key,
                                    )
                                        ? golemCardCatalog[key]?.name
                                        : relicCatalog[key]?.name;

                                    return (
                                        <span
                                            key={`${key}-${i}`}
                                            className="rounded-full border bg-muted/40 px-2 py-0.5 text-xs"
                                        >
                                            🔖 {name ?? key}
                                        </span>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
