<?php

namespace ErnestDefoe\Homecoming\Api;

use ErnestDefoe\Homecoming\RateLimit;
use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/homecoming/locate {lat, lng}: "Use my location". The browser has
 * already rounded the point to two decimals (about a kilometre); it is rounded
 * again here, never logged, and saved as the member's own place with the town
 * it is in. The member clicked for this, so the town lookup may run now.
 */
class LocateController implements RequestHandlerInterface
{
    private const PER_MINUTE = 5;

    private const FORUM_PER_MINUTE = 60;

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

        if (! (bool) $this->settings->get('ernestdefoe-homecoming.weather') || ! (bool) $this->settings->get('ernestdefoe-homecoming.geolocate')) {
            return new JsonResponse(['place' => null], 404);
        }

        $body = (array) $request->getParsedBody();
        $lat = Arr::get($body, 'lat');
        $lng = Arr::get($body, 'lng');
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            throw new ValidationException(['place' => 'No location.']);
        }
        $lat = round((float) $lat, 2);
        $lng = round((float) $lng, 2);

        if (! $this->limits->take('homecoming.locate.'.$actor->id, self::PER_MINUTE) || ! $this->limits->take('homecoming.locate', self::FORUM_PER_MINUTE)) {
            return new JsonResponse(['place' => null, 'error' => 'busy'], 429);
        }

        // No town name (the lookup failed): still the member's weather, shown as "your location".
        $label = $this->places->reverse($lat, $lng, $this->locales->getLocale()) ?? '';
        $this->places->save($actor, $lat, $lng, $label);

        return new JsonResponse(['place' => ['label' => $label, 'source' => 'own']]);
    }
}
