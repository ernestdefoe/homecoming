<?php

namespace ErnestDefoe\Homecoming\Weather;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;

/**
 * The weather for an area, read from the cache only.
 *
 * 🚨 A page never calls a third party. A miss returns null (no weather line)
 * and queues one fetch for the area; the member sees it on their next load.
 * Areas are a tenth of a degree (about 10 km), so a town shares one entry.
 * At most one fetch per area is in flight, fetches are capped per minute
 * forum-wide, and a failed fetch is remembered for ten minutes so an outage
 * can't turn into a storm of retries.
 */
class Forecasts
{
    public const TTL = 1800;

    public const FAILED_TTL = 600;

    public const TIMEOUT = 3;

    public function __construct(
        private readonly Cache $cache,
        private readonly Queue $queue,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Container $container,
    ) {
    }

    /** @return array{0: float, 1: float} */
    public static function area(float $lat, float $lng): array
    {
        return [round($lat, 1), round($lng, 1)];
    }

    /** @return array<string, mixed>|null */
    public function for(float $lat, float $lng): ?array
    {
        [$lat, $lng] = self::area($lat, $lng);
        $hit = $this->cache->get($this->key($lat, $lng));

        if (is_array($hit)) {
            return empty($hit['failed']) ? $hit : null;
        }

        $this->request($lat, $lng);

        return null;
    }

    /** @param array<string, mixed> $forecast */
    public function remember(float $lat, float $lng, array $forecast): void
    {
        $this->cache->put($this->key($lat, $lng), $forecast, self::TTL);
        $this->cache->forget($this->key($lat, $lng).'.q');
    }

    public function failed(float $lat, float $lng): void
    {
        $this->cache->put($this->key($lat, $lng), ['failed' => true], self::FAILED_TTL);
        $this->cache->forget($this->key($lat, $lng).'.q');
    }

    private function key(float $lat, float $lng): string
    {
        return sprintf('homecoming.wx.%.1f_%.1f', $lat, $lng);
    }

    private function request(float $lat, float $lng): void
    {
        $pending = $this->key($lat, $lng).'.q';
        // One fetch in flight per area; the marker expires if a worker never gets to it.
        if (! $this->cache->add($pending, 1, 120)) {
            return;
        }
        if (! $this->underCap()) {
            $this->cache->forget($pending);

            return;
        }

        $job = new FetchForecast($lat, $lng);

        // Core wraps the driver in a RoutingQueue: look through it for "no worker".
        $driver = method_exists($this->queue, 'getDriver') ? $this->queue->getDriver() : $this->queue;
        if ($driver instanceof SyncQueue) {
            // No worker: fetch after the response has gone out, never while the member waits.
            register_shutdown_function(function () use ($job) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->container->call([$job, 'handle']);
            });

            return;
        }

        $this->queue->push($job);
    }

    /** Fetches this minute, forum-wide. One key, so a file cache never collects a file per minute. */
    private function underCap(): bool
    {
        $cap = max(1, (int) $this->settings->get('ernestdefoe-homecoming.weather_cap'));
        $minute = intdiv(time(), 60);
        $count = $this->cache->get('homecoming.wxcap');
        if (! is_array($count) || ($count['m'] ?? null) !== $minute) {
            $count = ['m' => $minute, 'n' => 0];
        }
        if ($count['n'] >= $cap) {
            return false;
        }
        $count['n']++;
        $this->cache->put('homecoming.wxcap', $count, 120);

        return true;
    }
}
