<?php

namespace ErnestDefoe\Homecoming\Api;

use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use ErnestDefoe\Homecoming\RateLimit;
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

    /** For the whole forum, so many accounts can't spend the geocoder's quota either. */
    private const FORUM_PER_MINUTE = 120;

    public function __construct(
        private readonly Places $places,
        private readonly SettingsRepositoryInterface $settings,
        private readonly RateLimit $limits,
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

        if (! $this->limits->take('homecoming.geo.'.$actor->id, self::PER_MINUTE) || ! $this->limits->take('homecoming.geo', self::FORUM_PER_MINUTE)) {
            return new JsonResponse(['places' => [], 'error' => 'busy'], 429);
        }

        $places = $this->places->search($query, $this->locales->getLocale());

        return $places === null
            ? new JsonResponse(['places' => [], 'error' => 'unavailable'], 503)
            : new JsonResponse(['places' => $places]);
    }
}
