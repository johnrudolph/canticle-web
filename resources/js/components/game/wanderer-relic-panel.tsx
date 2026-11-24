import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { EquipSlot, RelicCatalogEntry, WandererState } from '@/types/game';
import { HealthBar } from './health-bar';
import { RoleAvatar } from './role-avatar';
import { WandererDetailDialog } from './wanderer-detail-dialog';

// "Use" always submits with no extra input, except where a relic's
// engine behavior needs more and has its own dedicated dialog routed
// through here (an-insight-from-the-future, cabalist-brand). Relics
// needing extra input with no such dialog yet (e.g. Artist's insight's
// `directions` payload for its 🦶2) aren't reachable from this UI —
// the server still executes them correctly, there's just no board-click
// flow wired up to supply the extra input.

export const SLOT_LABELS: Record<EquipSlot, string> = {
    body: '🥋 Body',
    mind: '🧠 Mind',
    hand_left: '🖐️ Hand',
    hand_right: '🖐️ Hand',
    bag: '👝 Bag',
};

const RELIC_SLOT_TO_EQUIP_SLOTS: Record<string, EquipSlot[]> = {
    Body: ['body'],
    Mind: ['mind'],
    Bag: ['bag'],
    Hand: ['hand_left', 'hand_right'],
};

// Shame/Scar are status markers, not Relics — the engine just stores
// them in an equip slot (there's no separate status area on the mat),
// so every slot render has to special-case these two sentinel keys
// rather than looking them up in the Relic catalog.
const STATUS_TOKEN_LABELS: Record<string, string> = {
    shame_token: '😳 Shame token',
    scar_token: '🩹 Scar token',
};

function isStatusToken(key: string): boolean {
    return key in STATUS_TOKEN_LABELS;
}

function relicLabel(
    key: string,
    catalog: Record<string, RelicCatalogEntry>,
): string {
    if (isStatusToken(key)) return STATUS_TOKEN_LABELS[key];
    const entry = catalog[key];
    return entry ? `${entry.slot_emoji} ${entry.name}` : key;
}

function baseRelicKey(key: string): string {
    return key.replace(/-\d+$/, '');
}

/** Relics whose "Use" needs more input than the generic submit supplies, routed through onUsePrompted instead of firing use_relic directly. */
const PROMPTED_RELICS = new Set([
    'holy-water',
    'quantum-robes',
    'teleportation-stone',
    'hands-of-deliverance',
    'warlocks-robes',
    'magistrates-robes',
    'divine-cabalist-pyre',
    'armistice',
    'cabalist-brew',
    'golem-log',
    'merchants-gloves',
    'artists-insight',
    'golem-prod',
]);

/**
 * Relics whose engine behavior actually reads an Ally/target_role —
 * only these get a "Use on {ally}" button. Every other Action relic
 * (Inscribe, Golem log, Artist's insight, etc.) has no such concept —
 * confirmed in prod: Inscribe offered "Use on Wanderer B" even though
 * its only target is the Golem's own mat, and submitting it would've
 * just silently ignored target_role server-side.
 */
const ALLY_TARGETABLE_RELICS = new Set([
    'holy-water',
    'quantum-robes',
    'teleportation-stone',
    'warlocks-robes',
    'cabalist-brew',
    'divine-healing-oil',
    'last-rites',
    'emperors-robes',
    'hands-of-forgiveness',
]);

const TUCK_LABELS: Record<'bazaar' | 'cathedral', string> = {
    bazaar: 'Tuck (Bazaar, 🌿1)',
    cathedral: 'Tuck (Cathedral)',
};

function TuckButton({
    relicKey,
    tuckLocationKey,
    processing,
    onSubmit,
}: {
    relicKey: string;
    tuckLocationKey: 'bazaar' | 'cathedral';
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    return (
        <Button
            size="sm"
            variant="outline"
            className="h-5 px-1.5 text-[10px]"
            disabled={processing}
            onClick={() =>
                onSubmit({
                    type:
                        tuckLocationKey === 'bazaar'
                            ? 'tuck_relic_at_bazaar'
                            : 'tuck_relic_at_cathedral',
                    relic_key: relicKey,
                })
            }
        >
            {TUCK_LABELS[tuckLocationKey]}
        </Button>
    );
}

function DropButton({
    relicKey,
    entry,
    processing,
    onSubmit,
}: {
    relicKey: string;
    entry: RelicCatalogEntry | undefined;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
}) {
    const bound = entry?.is_bound === true;
    const button = (
        <button
            type="button"
            disabled={processing || bound}
            className="text-muted-foreground hover:text-destructive disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:text-muted-foreground"
            onClick={() =>
                onSubmit({ type: 'drop_relic', relic_key: relicKey })
            }
        >
            drop
        </button>
    );

    if (!bound) return button;

    return (
        <Tooltip>
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            <TooltipContent>
                Bound Relics can't ever be dropped, tucked, trashed, or traded
                by choice — only trashed by their own effect.
            </TooltipContent>
        </Tooltip>
    );
}

export function WandererRelicPanel({
    role,
    wanderer,
    relicCatalog,
    processing,
    onSubmit,
    canAct,
    isActive = false,
    allies,
    tuckLocationKey = null,
    freeActions = [],
    relicUnusableReasons = {},
    onUseInsightFromTheFuture,
    onUseCabalistBrand,
    onUsePrompted,
}: {
    role: 'wanderer_a' | 'wanderer_b' | 'wanderer_c';
    wanderer: WandererState;
    relicCatalog: Record<string, RelicCatalogEntry>;
    processing: boolean;
    onSubmit: (move: Record<string, unknown>) => void;
    /** Whether this Wanderer can spend an action right now (their own active turn). */
    canAct: boolean;
    /** Whether it's this Wanderer's turn right now, regardless of who's viewing. */
    isActive?: boolean;
    /** Other Wanderers sharing this one's space, for Relics that target an Ally. */
    allies: { role: string; label: string }[];
    /**
     * Set when this Wanderer is on the Bazaar or Cathedral during their
     * own turn — both let you tuck a Relic from your mat here for free
     * (no action cost), so "tuck" buttons show on every equipped/held
     * Relic regardless of whether any actions remain.
     */
    tuckLocationKey?: 'bazaar' | 'cathedral' | null;
    /**
     * Stand-alone free actions not tied to any particular equipped
     * Relic slot — Perplexing's move-to-new-Location, Parallel's
     * skip-next-card, Purification pools' cleanse, Smoldering's trash-
     * for-health, Neighborly's assist, Magnetic mail/Telekinesis/
     * Laborer's Jacket's movement — whatever's currently available,
     * computed by the caller from board/Glyph/equipped state.
     */
    freeActions?: { label: string; onClick: () => void }[];
    /** Per equip-slot: why that Relic can't be used right now, or null if it can. Only the slots whose condition is checkable without a target are present. */
    relicUnusableReasons?: Record<string, string | null>;
    /**
     * An insight from the future needs to search the Emperor's hidden
     * Location deck before it can resolve — there's no way to supply
     * that choice through the generic "Use" submit, so its button
     * routes here instead (see InsightFromTheFutureDialog in show.tsx)
     * rather than firing use_relic directly.
     */
    onUseInsightFromTheFuture?: (relicKey: string) => void;
    /**
     * Cabalist brand needs to choose which (if any) of the Golem's
     * active Glyphs to discard before use_relic fires — same "Use"
     * can't supply it directly, routes here instead.
     */
    onUseCabalistBrand?: (relicKey: string) => void;
    /**
     * Catch-all for every other Relic whose "Use" (self or "Use on
     * {ally}") needs more input than the generic submit can supply —
     * Quantum robes, Teleportation stone, Hands of deliverance,
     * Warlock's robes, Magistrate's robes, Divine Cabalist pyre, Show
     * solidarity, Cabalist brew, Golem log, Merchant's gloves, Artist's
     * insight, Golem prod. Called with the ally's role when triggered
     * from a "Use on {ally}" button, undefined for the plain "Use".
     */
    onUsePrompted?: (relicKey: string, allyRole?: string) => void;
}) {
    const [detailOpen, setDetailOpen] = useState(false);
    const label = `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;

    return (
        <div
            className={`rounded-md border bg-card p-2 text-xs ${
                isActive ? 'border-primary ring-1 ring-primary' : ''
            }`}
        >
            <button
                type="button"
                onClick={() => setDetailOpen(true)}
                className="mb-1.5 flex w-full items-center gap-2 text-left hover:opacity-80"
            >
                <RoleAvatar role={role} size="lg" />
                <div className="flex-1">
                    <p className="font-medium">
                        {label}
                        {isActive && (
                            <span className="ml-2 rounded bg-primary px-1.5 py-0.5 text-[10px] text-primary-foreground">
                                {wanderer.actions_remaining} action
                                {wanderer.actions_remaining === 1
                                    ? ''
                                    : 's'}{' '}
                                left
                            </span>
                        )}
                    </p>
                    <HealthBar hp={wanderer.hp} className="mt-1" />
                </div>
            </button>

            {canAct && (
                <Button
                    size="sm"
                    variant="outline"
                    className="mb-1.5 h-6 w-full px-1.5 text-[10px]"
                    disabled={
                        processing ||
                        wanderer.hp >= 15 ||
                        wanderer.actions_remaining <= 0
                    }
                    onClick={() => onSubmit({ type: 'rest' })}
                >
                    🌿 Rest (+1 Health)
                </Button>
            )}

            {freeActions.length > 0 && (
                <div className="mb-1.5 flex flex-wrap gap-1">
                    {freeActions.map(({ label: actionLabel, onClick }) => (
                        <Button
                            key={actionLabel}
                            size="sm"
                            variant="outline"
                            className="h-6 flex-1 px-1.5 text-[10px]"
                            disabled={processing}
                            onClick={onClick}
                        >
                            {actionLabel}
                        </Button>
                    ))}
                </div>
            )}

            <div className="grid grid-cols-2 gap-1">
                {(Object.keys(SLOT_LABELS) as EquipSlot[]).map((slot) => {
                    const relicKey = wanderer.equipped[slot];
                    return (
                        <div key={slot} className="rounded border px-1.5 py-1">
                            <p className="text-muted-foreground">
                                {SLOT_LABELS[slot]}
                            </p>
                            {relicKey && isStatusToken(relicKey) ? (
                                <span className="truncate">
                                    {relicLabel(relicKey, relicCatalog)}
                                </span>
                            ) : relicKey ? (
                                <div className="flex flex-col gap-1">
                                    <div className="flex items-center justify-between gap-1">
                                        <span className="truncate">
                                            {relicLabel(relicKey, relicCatalog)}
                                        </span>
                                        <DropButton
                                            relicKey={relicKey}
                                            entry={relicCatalog[relicKey]}
                                            processing={processing}
                                            onSubmit={onSubmit}
                                        />
                                    </div>
                                    {relicCatalog[relicKey]?.effect && (
                                        <p className="text-[10px] text-muted-foreground">
                                            {relicCatalog[relicKey].effect}
                                        </p>
                                    )}
                                    {tuckLocationKey && (
                                        <TuckButton
                                            relicKey={relicKey}
                                            tuckLocationKey={tuckLocationKey}
                                            processing={processing}
                                            onSubmit={onSubmit}
                                        />
                                    )}
                                    {canAct &&
                                        relicCatalog[relicKey]?.is_action && (
                                            <div className="flex flex-wrap gap-1">
                                                {(() => {
                                                    const reason =
                                                        relicUnusableReasons[
                                                            slot
                                                        ];
                                                    const useButton = (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-5 px-1.5 text-[10px]"
                                                            disabled={
                                                                processing ||
                                                                !!reason
                                                            }
                                                            onClick={() => {
                                                                const base =
                                                                    baseRelicKey(
                                                                        relicKey,
                                                                    );
                                                                if (
                                                                    base ===
                                                                        'an-insight-from-the-future' &&
                                                                    onUseInsightFromTheFuture
                                                                ) {
                                                                    onUseInsightFromTheFuture(
                                                                        relicKey,
                                                                    );
                                                                } else if (
                                                                    base ===
                                                                        'cabalist-brand' &&
                                                                    onUseCabalistBrand
                                                                ) {
                                                                    onUseCabalistBrand(
                                                                        relicKey,
                                                                    );
                                                                } else if (
                                                                    PROMPTED_RELICS.has(
                                                                        base,
                                                                    ) &&
                                                                    onUsePrompted
                                                                ) {
                                                                    onUsePrompted(
                                                                        relicKey,
                                                                    );
                                                                } else {
                                                                    onSubmit({
                                                                        type: 'use_relic',
                                                                        relic_key:
                                                                            relicKey,
                                                                    });
                                                                }
                                                            }}
                                                        >
                                                            Use
                                                        </Button>
                                                    );

                                                    if (!reason)
                                                        return useButton;

                                                    return (
                                                        <Tooltip>
                                                            <TooltipTrigger
                                                                asChild
                                                            >
                                                                <span>
                                                                    {useButton}
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                {reason}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    );
                                                })()}
                                                {ALLY_TARGETABLE_RELICS.has(
                                                    baseRelicKey(relicKey),
                                                ) &&
                                                    allies.map((ally) => (
                                                        <Button
                                                            key={ally.role}
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-5 px-1.5 text-[10px]"
                                                            disabled={
                                                                processing
                                                            }
                                                            onClick={() => {
                                                                const base =
                                                                    baseRelicKey(
                                                                        relicKey,
                                                                    );
                                                                if (
                                                                    PROMPTED_RELICS.has(
                                                                        base,
                                                                    ) &&
                                                                    onUsePrompted
                                                                ) {
                                                                    onUsePrompted(
                                                                        relicKey,
                                                                        ally.role,
                                                                    );
                                                                } else {
                                                                    onSubmit({
                                                                        type: 'use_relic',
                                                                        relic_key:
                                                                            relicKey,
                                                                        target_role:
                                                                            ally.role,
                                                                    });
                                                                }
                                                            }}
                                                        >
                                                            Use on {ally.label}
                                                        </Button>
                                                    ))}
                                            </div>
                                        )}
                                </div>
                            ) : (
                                <span className="text-muted-foreground/60">
                                    empty
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>

            {wanderer.holding.length > 0 && (
                <div className="mt-2 space-y-1">
                    <p className="text-muted-foreground">Holding</p>
                    {wanderer.holding.map((relicKey) => {
                        const entry = relicCatalog[relicKey];
                        const equipSlots = entry
                            ? (RELIC_SLOT_TO_EQUIP_SLOTS[entry.slot] ?? [])
                            : [];

                        return (
                            <div
                                key={relicKey}
                                className="rounded border px-1.5 py-1"
                            >
                                <div className="flex items-center justify-between gap-1">
                                    <span className="truncate">
                                        {relicLabel(relicKey, relicCatalog)}
                                    </span>
                                    <div className="flex gap-1">
                                        {equipSlots.map((slot) => (
                                            <Button
                                                key={slot}
                                                size="sm"
                                                variant="outline"
                                                className="h-5 px-1.5 text-[10px]"
                                                disabled={processing}
                                                onClick={() =>
                                                    onSubmit({
                                                        type: 'equip_relic',
                                                        relic_key: relicKey,
                                                        slot,
                                                    })
                                                }
                                            >
                                                Equip {SLOT_LABELS[slot]}
                                            </Button>
                                        ))}
                                        {tuckLocationKey &&
                                            entry?.is_bound !== true && (
                                                <TuckButton
                                                    relicKey={relicKey}
                                                    tuckLocationKey={
                                                        tuckLocationKey
                                                    }
                                                    processing={processing}
                                                    onSubmit={onSubmit}
                                                />
                                            )}
                                        <DropButton
                                            relicKey={relicKey}
                                            entry={entry}
                                            processing={processing}
                                            onSubmit={onSubmit}
                                        />
                                    </div>
                                </div>
                                {entry?.effect && (
                                    <p className="mt-0.5 text-[10px] text-muted-foreground">
                                        {entry.effect}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            <WandererDetailDialog
                open={detailOpen}
                onOpenChange={setDetailOpen}
                role={role}
                wanderer={wanderer}
                relicCatalog={relicCatalog}
                canAct={canAct}
                processing={processing}
                onSubmit={onSubmit}
            />
        </div>
    );
}
