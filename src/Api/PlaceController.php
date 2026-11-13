<?php

namespace ErnestDefoe\Homecoming\Api;

use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/homecoming/place {lat, lng, label}: the member's own town for the
 * weather (a result of the search). DELETE: back to Member Map, or none.
 */
class PlaceController implements RequestHandlerInterface
{
    public function __construct(private readonly Places $places)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if ($request->getMethod() === 'DELETE') {
            $this->places->forget($actor);

            return new EmptyResponse(204);
        }

        $body = (array) $request->getParsedBody();
        $lat = Arr::get($body, 'lat');
        $lng = Arr::get($body, 'lng');
        $label = trim(strip_tags((string) Arr::get($body, 'label', '')));

        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || $label === '') {
            throw new ValidationException(['place' => 'Pick a town from the list.']);
        }

        $this->places->save($actor, (float) $lat, (float) $lng, mb_substr($label, 0, 150));

        return new EmptyResponse(204);
    }
}
