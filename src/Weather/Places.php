<?php

namespace ErnestDefoe\Homecoming\Weather;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Where a member's weather is for: the town they picked here, else their
 * Member Map place. Neither: no weather.
 *
 * Picking a town searches Open-Meteo's geocoder from the server, when the
 * member asks (never while a page draws), and keeps the town's centre.
 */
class Places
{
    public const GEOCODER = 'https://geocoding-api.open-meteo.com/v1/search';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /** @return array{lat: float, lng: float, label: string, source: string}|null */
    public function for(User $user): ?array
    {
        $own = $this->db->table('homecoming_places')->where('user_id', $user->id)->first(['lat', 'lng', 'label']);
        if ($own) {
            return ['lat' => (float) $own->lat, 'lng' => (float) $own->lng, 'label' => (string) $own->label, 'source' => 'own'];
        }

        if ($this->extensions->isEnabled('ernestdefoe-member-map')) {
            $map = $this->db->table('member_map_locations')->where('user_id', $user->id)->first(['lat', 'lng', 'label']);
            if ($map) {
                return ['lat' => (float) $map->lat, 'lng' => (float) $map->lng, 'label' => (string) $map->label, 'source' => 'member-map'];
            }
        }

        return null;
    }

    public function save(User $user, float $lat, float $lng, string $label): void
    {
        $this->db->table('homecoming_places')->updateOrInsert(
            ['user_id' => $user->id],
            ['lat' => round($lat, 2), 'lng' => round($lng, 2), 'label' => $label, 'updated_at' => Carbon::now()]
        );
    }

    public function forget(User $user): void
    {
        $this->db->table('homecoming_places')->where('user_id', $user->id)->delete();
    }

    /**
     * Towns matching what the member typed.
     *
     * @return list<array{label: string, lat: float, lng: float}>|null null when the geocoder can't be reached
     */
    public function search(string $query, string $locale): ?array
    {
        try {
            $response = (new Client(Forecasts::HTTP))
                ->get(self::GEOCODER, ['query' => [
                    'name' => $query,
                    'count' => 6,
                    'language' => preg_match('/^[a-z]{2}/', $locale, $m) ? $m[0] : 'en',
                    'format' => 'json',
                ]]);
            $body = json_decode($response->getBody()->read(Forecasts::MAX_BYTES), true);
        } catch (Throwable) {
            return null;
        }

        $places = [];
        foreach ((is_array($body) && is_array($body['results'] ?? null)) ? $body['results'] : [] as $row) {
            if (! is_array($row) || ! is_numeric($row['latitude'] ?? null) || ! is_numeric($row['longitude'] ?? null) || ! is_string($row['name'] ?? null)) {
                continue;
            }
            $parts = array_filter([$row['name'], $row['admin1'] ?? null, $row['country'] ?? null], fn ($p) => is_string($p) && $p !== '');
            $places[] = [
                'label' => mb_substr(implode(', ', array_unique($parts)), 0, 150),
                'lat' => round((float) $row['latitude'], 2),
                'lng' => round((float) $row['longitude'], 2),
            ];
        }

        return $places;
    }
}
