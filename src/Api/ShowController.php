<?php

namespace ErnestDefoe\Homecoming\Api;

use ErnestDefoe\Homecoming\Dashboard;
use ErnestDefoe\Homecoming\Weather\Forecasts;
use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/homecoming: the signed-in member's own dashboard. Nothing here is
 * about anyone else, so it is never shared between members.
 */
class ShowController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dashboard $dashboard,
        private readonly Places $places,
        private readonly Forecasts $forecasts,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $data = $this->dashboard->for($actor);
        $data['place'] = null;
        $data['weather'] = null;

        if ((bool) $this->settings->get('ernestdefoe-homecoming.weather')) {
            $place = $this->places->for($actor);
            if ($place) {
                $data['place'] = ['label' => $place['label'], 'source' => $place['source']];
                $data['weather'] = $this->forecasts->for($place['lat'], $place['lng']);
            }
        }

        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
