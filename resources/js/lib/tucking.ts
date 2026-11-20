/**
 * Which Location keys tuck Golem card keys (rather than Relic keys) into
 * their board cell's `tucked` list — Defenders' Guild and House of
 * Cryptids both return their 4 tucked cards to the Golem's discard pile
 * for 📜📜; everywhere else that tucks (Bazaar, Cathedral, Golem
 * sanctuary) tucks Relics instead.
 */
export const GOLEM_CARD_TUCKING_LOCATIONS = new Set([
    'defenders-guild',
    'house-of-cryptids',
]);

export function tucksGolemCards(locationKey: string): boolean {
    return GOLEM_CARD_TUCKING_LOCATIONS.has(locationKey);
}
