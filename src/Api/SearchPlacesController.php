<?php

namespace ErnestDefoe\Homecoming\Api;

use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/homecoming/places?q=…: towns for the member's weather. A member
 * typing in their settings, so it may reach the geocoder, at most ten times
 * a minute per member.
 */
class SearchPlacesController implements RequestHandlerInterface
{
    private const PER_MINUTE = 10;

    public function __construct(
        private readonly Places $places,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Cache $cache,
        private readonly LocaleManager $locales,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! (bool) $this->settings->get('ernestdefoe-homecoming.weather')) {
            return new JsonResponse(['places' => []], 404);
        }

        $query = trim((string) Arr::get($request->getQueryParams(), 'q', ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 80) {
            return new JsonResponse(['places' => []]);
        }

        // One key per member, so a file cache never collects a file per request.
        $key = 'homecoming.geo.'.$actor->id;
        $minute = intdiv(time(), 60);
        $count = $this->cache->get($key);
        if (! is_array($count) || ($count['m'] ?? null) !== $minute) {
            $count = ['m' => $minute, 'n' => 0];
        }
        if ($count['n'] >= self::PER_MINUTE) {
            return new JsonResponse(['places' => [], 'error' => 'busy'], 429);
        }
        $count['n']++;
        $this->cache->put($key, $count, 120);

        $places = $this->places->search($query, $this->locales->getLocale());

        return $places === null
            ? new JsonResponse(['places' => [], 'error' => 'unavailable'], 503)
            : new JsonResponse(['places' => $places]);
    }
}
