<?php

namespace ErnestDefoe\Homecoming\Tests\integration\Fixtures;

use ErnestDefoe\Homecoming\Weather\Places;

/** Places without the network: records what it was asked, answers from fixed data. */
class FakePlaces extends Places
{
    /** @var list<array{0: float, 1: float}> */
    public static array $reversed = [];

    public function reverse(float $lat, float $lng, string $locale): ?string
    {
        self::$reversed[] = [$lat, $lng];

        return 'Nashville, Tennessee, United States';
    }

    public function search(string $query, string $locale): ?array
    {
        return $query === 'Nowhere' ? [] : [['label' => 'Portland, Oregon, United States', 'lat' => 45.52, 'lng' => -122.68]];
    }
}
