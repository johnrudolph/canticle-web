<?php

namespace App\Models\Cards;

/**
 * The one fixed branch taxonomy shared across every card type: a
 * Location's `produces`/`costs`, and a Relic's `symbol`. Single source
 * of truth — these used to be two separately hardcoded lists
 * (LocationController::BRANCH_EMOJI, RelicController::RELIC_SYMBOLS),
 * plus a third copy in the frontend's admin-columns.ts, and they drifted
 * out of sync (Music got added to some but not all of them). Every
 * controller/column list that needs "the branches" or "the branch
 * emoji" should read it from here now, not redeclare it.
 */
enum Branch: string
{
    case Agriculture = 'Agriculture';
    case Art = 'Art';
    case Commerce = 'Commerce';
    case Dark = 'Dark';
    case Engineering = 'Engineering';
    case Fire = 'Fire';
    case Golem = 'Golem';
    case Law = 'Law';
    case Magic = 'Magic';
    case Music = 'Music';
    case Religion = 'Religion';
    case Solidarity = 'Solidarity';
    case Time = 'Time';

    public function emoji(): string
    {
        return match ($this) {
            self::Agriculture => '🌾',
            self::Art => '🎨',
            self::Commerce => '🪙',
            self::Dark => '🌑',
            self::Engineering => '⚙️',
            self::Fire => '🔥',
            self::Golem => '🐾',
            self::Law => '⚖️',
            self::Magic => '✨',
            self::Music => '🎶',
            self::Religion => '📖',
            self::Solidarity => '🤝',
            self::Time => '⏳',
        };
    }

    /**
     * Branch name -> emoji, in declaration order — what a Location's
     * produces/costs picker offers (every branch is a valid thing to
     * produce or cost, even the 3 with no Relic deck).
     *
     * @return array<string, string>
     */
    public static function emojiMap(): array
    {
        $map = [];

        foreach (self::cases() as $branch) {
            $map[$branch->value] = $branch->emoji();
        }

        return $map;
    }

    /**
     * Branches that actually have Relics filed under them — Agriculture/
     * Commerce/Engineering never do (confirmed against key.json; no
     * Relic type exists for them). What a Relic's `symbol` picker
     * offers.
     *
     * @return list<self>
     */
    public static function withRelics(): array
    {
        $excluded = [self::Agriculture, self::Commerce, self::Engineering];

        return array_values(array_filter(
            self::cases(),
            fn (self $branch) => ! in_array($branch, $excluded, true),
        ));
    }

    /**
     * @return list<string>
     */
    public static function relicSymbols(): array
    {
        return array_map(fn (self $branch) => $branch->emoji(), self::withRelics());
    }
}
