<?php

namespace ErnestDefoe\Homecoming\Weather;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Where a member's weather is for: their own place (a town they picked, or
 * where their device said they are), else their Member Map place, else the
 * forum's default town. None: no weather.
 *
 * Every lookup here runs when someone asks (a member searching or using their
 * location, an admin saving the default town), never while a page draws.
 */
class Places
{
    public const GEOCODER = 'https://geocoding-api.open-meteo.com/v1/search';

    /** OpenStreetMap's reverse geocoder: coordinates to a town name. */
    public const REVERSE = 'https://nominatim.openstreetmap.org/reverse';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
        private readonly SettingsRepositoryInterface $settings,
        private readonly UrlGenerator $url,
        private readonly Cache $cache,
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

        $lat = $this->settings->get('ernestdefoe-homecoming.default_lat');
        $lng = $this->settings->get('ernestdefoe-homecoming.default_lng');
        if (is_numeric($lat) && is_numeric($lng)) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng, 'label' => (string) $this->settings->get('ernestdefoe-homecoming.default_label'), 'source' => 'default'];
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

    /**
     * The town at a point, as "Town, Region, Country", or null. The point is
     * already rounded to about a kilometre.
     *
     * Nominatim's usage policy: at most one request a second for the whole
     * application, an identifying client header, and no repeat lookups. So
     * names are kept per 0.01° cell for a week (a failed lookup for ten
     * minutes), and only one lookup a second leaves the forum: a second one
     * waits a second once, then gives up (the weather then says "your
     * location").
     */
    public function reverse(float $lat, float $lng, string $locale): ?string
    {
        $lang = preg_match('/^[a-z]{2}/', $locale, $m) ? $m[0] : 'en';
        $key = sprintf('homecoming.rev.%s.%.2f_%.2f', $lang, round($lat, 2), round($lng, 2));

        $hit = $this->cache->get($key);
        if (is_string($hit)) {
            return $hit === '' ? null : $hit;
        }

        if (! $this->slot()) {
            return null;
        }

        $name = $this->lookup(round($lat, 2), round($lng, 2), $lang);
        $this->cache->put($key, $name ?? '', $name === null ? 600 : 604800);

        return $name;
    }

    /** One request to Nominatim. */
    protected function lookup(float $lat, float $lng, string $lang): ?string
    {
        try {
            $response = (new Client(Forecasts::HTTP + ['headers' => ['User-Agent' => 'Homecoming (Flarum extension; '.$this->url->to('forum')->base().')']]))
                ->get(self::REVERSE, ['query' => [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 10,
                    'accept-language' => $lang,
                ]]);
            $body = json_decode($response->getBody()->read(Forecasts::MAX_BYTES), true);
        } catch (Throwable) {
            return null;
        }

        $a = is_array($body) && is_array($body['address'] ?? null) ? $body['address'] : [];
        $town = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? null;
        $parts = array_filter([$town, $a['state'] ?? null, $a['country'] ?? null], fn ($p) => is_string($p) && $p !== '');

        return $parts ? mb_substr(implode(', ', array_unique($parts)), 0, 150) : null;
    }

    /** The forum's one Nominatim request this second: wait a second once if it's taken. */
    private function slot(): bool
    {
        for ($try = 0; $try < 2; $try++) {
            if ($this->cache->add('homecoming.nominatim', 1, 1)) {
                return true;
            }
            usleep(1_100_000);
        }

        return false;
    }
}
