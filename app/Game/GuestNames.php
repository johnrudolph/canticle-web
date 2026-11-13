<?php

namespace App\Game;

final class GuestNames
{
    private const ADJECTIVES = [
        'Clever', 'Swift', 'Bold', 'Sly', 'Mighty', 'Quiet', 'Fierce', 'Lucky',
        'Nimble', 'Wild', 'Brave', 'Grim', 'Jolly', 'Rusty', 'Shadowy', 'Golden',
    ];

    private const NOUNS = [
        'Fox', 'Raven', 'Wolf', 'Badger', 'Hawk', 'Otter', 'Stag', 'Bear',
        'Lynx', 'Falcon', 'Heron', 'Boar', 'Viper', 'Owl', 'Crane', 'Panther',
    ];

    public static function random(): string
    {
        $adjective = self::ADJECTIVES[array_rand(self::ADJECTIVES)];
        $noun = self::NOUNS[array_rand(self::NOUNS)];
        $number = random_int(10, 99);

        return "{$adjective} {$noun} {$number}";
    }
}
