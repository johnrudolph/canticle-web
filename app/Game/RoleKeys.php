<?php

namespace App\Game;

/**
 * The fixed role roster for a game. Roles are decoupled from players —
 * one player can claim multiple roles (solo play claims all of them), and
 * Wanderer count is fixed at exactly 2 or 3, never more or fewer.
 */
final class RoleKeys
{
    public const EMPEROR = 'emperor';

    public const GOLEM = 'golem';

    public const WANDERER_A = 'wanderer_a';

    public const WANDERER_B = 'wanderer_b';

    public const WANDERER_C = 'wanderer_c';

    /**
     * @return list<string>
     */
    public static function wanderers(int $wandererCount): array
    {
        return match ($wandererCount) {
            2 => [self::WANDERER_A, self::WANDERER_B],
            3 => [self::WANDERER_A, self::WANDERER_B, self::WANDERER_C],
            default => throw new \InvalidArgumentException("Unsupported wanderer count: {$wandererCount}"),
        };
    }

    /**
     * Every role that must be claimed before a game can start.
     *
     * @return list<string>
     */
    public static function all(int $wandererCount, bool $golemEnabled): array
    {
        return [
            ...self::wanderers($wandererCount),
            self::EMPEROR,
            ...($golemEnabled ? [self::GOLEM] : []),
        ];
    }

    public static function wandererLetter(string $roleKey): string
    {
        return match ($roleKey) {
            self::WANDERER_A => 'A',
            self::WANDERER_B => 'B',
            self::WANDERER_C => 'C',
            default => throw new \InvalidArgumentException("Not a Wanderer role: {$roleKey}"),
        };
    }
}
