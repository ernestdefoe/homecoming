<?php

namespace ErnestDefoe\Homecoming\Weather;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Throwable;

/**
 * One forecast for one area, from Open-Meteo (free, no key). Only the area's
 * rounded coordinates leave the forum: never a member, an IP or a name.
 */
class FetchForecast extends AbstractJob
{
    public const URL = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {
    }

    public function handle(Forecasts $forecasts, SettingsRepositoryInterface $settings): void
    {
        if (! (bool) $settings->get('ernestdefoe-homecoming.weather')) {
            return;
        }

        try {
            $response = (new Client(Forecasts::HTTP))
                ->get(self::URL, ['query' => [
                    'latitude' => $this->lat,
                    'longitude' => $this->lng,
                    'current' => 'temperature_2m,weather_code,is_day',
                    'daily' => 'temperature_2m_max,temperature_2m_min',
                    'timezone' => 'auto',
                    'forecast_days' => 1,
                ]]);
            $forecast = self::parse(json_decode($response->getBody()->read(Forecasts::MAX_BYTES), true));
        } catch (Throwable) {
            $forecast = null;
        }

        if ($forecast === null) {
            $forecasts->failed($this->lat, $this->lng);
        } else {
            $forecasts->remember($this->lat, $this->lng, $forecast);
        }
    }

    /**
     * Only the numbers we draw, all in °C (the client converts).
     *
     * @return array{temp: int, code: int, day: bool, high: ?int, low: ?int}|null
     */
    public static function parse(mixed $body): ?array
    {
        $current = is_array($body) ? ($body['current'] ?? null) : null;
        if (! is_array($current) || ! is_numeric($current['temperature_2m'] ?? null) || ! is_numeric($current['weather_code'] ?? null)) {
            return null;
        }
        $daily = is_array($body['daily'] ?? null) ? $body['daily'] : [];
        $first = fn (string $key) => is_numeric($daily[$key][0] ?? null) ? (int) round((float) $daily[$key][0]) : null;

        return [
            'temp' => (int) round((float) $current['temperature_2m']),
            'code' => (int) $current['weather_code'],
            'day' => (bool) ($current['is_day'] ?? true),
            'high' => $first('temperature_2m_max'),
            'low' => $first('temperature_2m_min'),
        ];
    }
}
