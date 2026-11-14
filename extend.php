<?php

/*
 * Homecoming: a welcome-back dashboard for members.
 *
 * The page never waits on us: forum.js draws the greeting, name, avatar,
 * group and notification count from the session user Flarum already sends,
 * then asks GET /api/homecoming once for the rest (unread discussions, the
 * chosen stats, rank progress, weather). That answer is kept per member for
 * two minutes. Guests get nothing (or a sign-up nudge drawn from no data).
 *
 * Weather is read from the cache only. A miss queues one fetch per area
 * (Weather\Forecasts): the page never calls a third party.
 */

use ErnestDefoe\Homecoming\Api;
use ErnestDefoe\Homecoming\Dashboard;
use ErnestDefoe\Homecoming\DefaultTown;
use ErnestDefoe\Homecoming\Widget;
use Flarum\Discussion\Event\UserRead;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Settings\Event\Saved;

$extenders = [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pieces are a lazy chunk in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/homecoming', 'homecoming.show', Api\ShowController::class)
        ->get('/homecoming/places', 'homecoming.places', Api\SearchPlacesController::class)
        ->post('/homecoming/place', 'homecoming.place.save', Api\PlaceController::class)
        ->post('/homecoming/locate', 'homecoming.locate', Api\LocateController::class)
        ->delete('/homecoming/place', 'homecoming.place.delete', Api\PlaceController::class),

    // 🚨 Literal keys: anything here runs while the forum boots, and a class constant would make
    // every request load our classes then (a worker with a stale autoload map fatals on all of them).
    (new Extend\Settings())
        ->default('ernestdefoe-homecoming.placement', 'top')
        ->default('ernestdefoe-homecoming.stat1', 'discussions')
        ->default('ernestdefoe-homecoming.stat2', 'posts')
        ->default('ernestdefoe-homecoming.stat3', 'likes')
        ->default('ernestdefoe-homecoming.stat4', 'joined')
        ->default('ernestdefoe-homecoming.unread_days', 30)
        ->default('ernestdefoe-homecoming.guest_nudge', false)
        ->default('ernestdefoe-homecoming.weather', true)
        ->default('ernestdefoe-homecoming.weather_unit', 'c')
        ->default('ernestdefoe-homecoming.weather_cap', 30)
        ->default('ernestdefoe-homecoming.geolocate', true)
        ->default('ernestdefoe-homecoming.default_town', '')
        ->serializeToForum('homecomingPlacement', 'ernestdefoe-homecoming.placement')
        ->serializeToForum('homecomingGuestNudge', 'ernestdefoe-homecoming.guest_nudge', 'boolval')
        ->serializeToForum('homecomingWeather', 'ernestdefoe-homecoming.weather', 'boolval')
        ->serializeToForum('homecomingUnit', 'ernestdefoe-homecoming.weather_unit')
        ->serializeToForum('homecomingGeolocate', 'ernestdefoe-homecoming.geolocate', 'boolval'),

    (new Extend\User())
        ->registerPreference('homecomingUnit', fn ($value) => in_array($value, ['c', 'f'], true) ? $value : null, null),

    // A member's own counts change when they read or post: drop their answer.
    (new Extend\Event())
        ->listen(UserRead::class, fn (UserRead $event) => resolve(Dashboard::class)->forget((int) $event->state->user_id))
        ->listen(Posted::class, fn (Posted $event) => resolve(Dashboard::class)->forget((int) $event->post->user_id))
        ->listen(Saved::class, DefaultTown::class),
];

// flarum/gdpr: export the member's weather place, and erase it in both modes.
$extenders[] = (new Extend\Conditional())->whenExtensionEnabled('flarum-gdpr', fn () => [
    (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\Homecoming\Gdpr\HomecomingPlace::class),
]);

// The blocks only offer the pieces in Page Builder's editor; the client draws them like every other host.
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(Widget\WelcomeStripBlock::class);
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(Widget\StatsCardBlock::class);
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(Widget\CompactCardBlock::class);
}

return $extenders;
