import type { InfrastructureType } from '@/types/game';

/**
 * Single source of truth for how each Infrastructure type reads in the
 * UI — label, and what it actually does (rules.md's own wording, kept
 * in sync by hand since Infrastructure isn't part of the synced sheet
 * data). Used both as a hover tooltip when placing one and as the
 * explicit description in a Location's spotlight.
 */
export const INFRASTRUCTURE_LABELS: Record<InfrastructureType, string> = {
    monolith_shard: '✨ Monolith Shard',
    road: '🧱 Road',
    walls: '🏰 Walls',
    fruit_tree: '🌳 Fruit tree',
};

export const INFRASTRUCTURE_DESCRIPTIONS: Record<InfrastructureType, string> = {
    monolith_shard:
        'No effect on its own, but many Relics interact with Shards. Only 1 per space.',
    road: "Moving from this space never costs a Wanderer an action. Connected Road spaces all produce each other's resources.",
    walls: "The Golem cannot enter this space. Can't be placed on the Golem's own space, and can't be placed in a way that fully traps the Golem out of every non-Walls Location.",
    fruit_tree:
        'A Wanderer who starts their turn on this space gains 1 action, which they must use this turn.',
};
