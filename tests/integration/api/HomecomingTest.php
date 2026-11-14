<?php

namespace ErnestDefoe\Homecoming\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Homecoming\Tests\integration\Fixtures\FakePlaces;
use ErnestDefoe\Homecoming\Weather\FetchForecast;
use ErnestDefoe\Homecoming\Weather\Forecasts;
use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\Attributes\Test;

class HomecomingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-homecoming');

        $at = fn (int $days) => Carbon::now()->subDays($days);
        $discussion = fn (int $id, int $ago, array $extra = []) => $extra + [
            'id' => $id, 'title' => "D$id", 'slug' => "d$id", 'user_id' => 1, 'created_at' => $at($ago),
            'first_post_id' => $id * 10 + 1, 'comment_count' => 1, 'last_post_id' => $id * 10 + 1, 'last_post_number' => 1,
            'last_posted_at' => $at($ago), 'is_private' => 0,
        ];
        $post = fn (int $discussion, int $user) => [
            'id' => $discussion * 10 + 1, 'discussion_id' => $discussion, 'number' => 1, 'user_id' => $user, 'type' => 'comment',
            'created_at' => Carbon::now(), 'content' => '<t><p>x</p></t>', 'is_private' => 0,
        ];

        $this->prepareDatabase([
            User::class => [$this->normalUser() + ['discussion_count' => 3, 'comment_count' => 7]],
            Discussion::class => [
                $discussion(1, 1),                           // unread
                $discussion(2, 2),                           // read below
                $discussion(3, 3, ['is_private' => 1]),      // private: never counted
                $discussion(4, 3, ['hidden_at' => Carbon::now()]), // hidden: never counted
                $discussion(5, 90),                          // older than the 30-day window
            ],
            Post::class => [$post(1, 1), $post(2, 1), $post(3, 1), $post(4, 1), $post(5, 1)],
            'discussion_user' => [['user_id' => 2, 'discussion_id' => 2, 'last_read_post_number' => 1]],
        ]);
    }

    /** @return array<string, mixed> */
    private function dashboard(int $as = 2): array
    {
        $response = $this->send($this->request('GET', '/api/homecoming', ['authenticatedAs' => $as]));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function guests_get_nothing(): void
    {
        $this->assertSame(401, $this->send($this->request('GET', '/api/homecoming'))->getStatusCode());
        $this->assertSame(401, $this->send($this->request('GET', '/api/homecoming/places', [])->withQueryParams(['q' => 'Paris']))->getStatusCode());
    }

    #[Test]
    public function unread_counts_only_recent_visible_unread_public_discussions(): void
    {
        $this->assertSame(1, $this->dashboard()['unread']);
    }

    #[Test]
    public function stats_follow_the_admin_and_skip_what_is_not_installed(): void
    {
        $this->setting('ernestdefoe-homecoming.stat1', 'posts');
        $this->setting('ernestdefoe-homecoming.stat2', 'collections'); // Curio isn't installed
        $this->setting('ernestdefoe-homecoming.stat3', 'posts');       // a repeat
        $this->setting('ernestdefoe-homecoming.stat4', 'joined');

        $stats = $this->dashboard()['stats'];
        $this->assertSame(['posts', 'joined', 'discussions'], array_column($stats, 'key'));
        $this->assertSame(7, $stats[0]['value']);
        $this->assertNull($this->dashboard()['progress']);
    }

    #[Test]
    public function a_member_picks_a_town_and_weather_is_read_from_the_cache_only(): void
    {
        $bad = $this->send($this->request('POST', '/api/homecoming/place', ['authenticatedAs' => 2, 'json' => ['lat' => 200, 'lng' => 0, 'label' => 'Nowhere']]));
        $this->assertSame(422, $bad->getStatusCode());

        $ok = $this->send($this->request('POST', '/api/homecoming/place', ['authenticatedAs' => 2, 'json' => ['lat' => 36.1627, 'lng' => -86.7816, 'label' => '<b>Nashville</b>, Tennessee']]));
        $this->assertSame(204, $ok->getStatusCode());

        $this->app();
        $cache = $this->app()->getContainer()->make(Cache::class);
        // A forecast already fetched for the area (a tenth of a degree) is shown as it is.
        $cache->put('homecoming.wx.36.2_-86.8', ['temp' => 21, 'code' => 2, 'day' => true, 'high' => 25, 'low' => 14], 60);

        $data = $this->dashboard();
        $this->assertSame(['label' => 'Nashville, Tennessee', 'source' => 'own'], $data['place']);
        $this->assertSame(21, $data['weather']['temp']);

        // A failed fetch is remembered as "no weather", never retried on every load.
        $cache->put('homecoming.wx.36.2_-86.8', ['failed' => true], 60);
        $this->assertNull($this->dashboard()['weather']);

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/homecoming/place', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertNull($this->dashboard()['place']);
    }

    #[Test]
    public function weather_can_be_switched_off(): void
    {
        $this->setting('ernestdefoe-homecoming.weather', '0');
        $this->send($this->request('POST', '/api/homecoming/place', ['authenticatedAs' => 2, 'json' => ['lat' => 1, 'lng' => 1, 'label' => 'Somewhere']]));

        $data = $this->dashboard();
        $this->assertNull($data['place']);
        $this->assertNull($data['weather']);
    }

    #[Test]
    public function forecasts_keep_only_the_numbers_we_draw(): void
    {
        $this->assertNull(FetchForecast::parse(['current' => ['temperature_2m' => 'warm']]));
        $this->assertSame(
            ['temp' => 18, 'code' => 61, 'day' => false, 'high' => 20, 'low' => 9],
            FetchForecast::parse(['current' => ['temperature_2m' => 17.6, 'weather_code' => 61, 'is_day' => 0], 'daily' => ['temperature_2m_max' => [19.7], 'temperature_2m_min' => [9.2]]])
        );
        $this->assertSame([36.2, -86.8], Forecasts::area(36.1627, -86.7816));
    }

    private function fakePlaces(): void
    {
        $c = $this->app()->getContainer();
        $c->bind(Places::class, FakePlaces::class);
        FakePlaces::$reversed = [];
    }

    #[Test]
    public function use_my_location_keeps_a_rounded_point_and_its_town(): void
    {
        // A guest is refused (by the CSRF check or the login check) and nothing is saved.
        $this->assertContains($this->send($this->request('POST', '/api/homecoming/locate', ['json' => ['lat' => 1, 'lng' => 1]]))->getStatusCode(), [400, 401]);
        $this->assertSame(0, $this->database()->table('homecoming_places')->count());

        $this->fakePlaces();
        $res = $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 36.162664, 'lng' => -86.781602]]));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([[36.16, -86.78]], FakePlaces::$reversed, 'only the rounded point reaches the town lookup');

        $row = $this->database()->table('homecoming_places')->where('user_id', 2)->first();
        $this->assertSame(['36.16', '-86.78', 'Nashville, Tennessee, United States'], [(string) (float) $row->lat, (string) (float) $row->lng, $row->label]);
        $this->assertSame(['label' => 'Nashville, Tennessee, United States', 'source' => 'own'], $this->dashboard()['place']);

        $bad = $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 'here', 'lng' => 0]]));
        $this->assertSame(422, $bad->getStatusCode());
    }

    #[Test]
    public function use_my_location_can_be_switched_off(): void
    {
        $this->setting('ernestdefoe-homecoming.geolocate', '0');
        $this->fakePlaces();
        $res = $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 36.16, 'lng' => -86.78]]));
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame([], FakePlaces::$reversed);
    }

    #[Test]
    public function the_forum_default_town_comes_after_the_members_own_place(): void
    {
        $this->fakePlaces();
        $saved = $this->send($this->request('POST', '/api/settings', ['authenticatedAs' => 1, 'json' => ['ernestdefoe-homecoming.default_town' => 'Portland']]));
        $this->assertSame(204, $saved->getStatusCode());
        $this->assertSame(['label' => 'Portland, Oregon, United States', 'source' => 'default'], $this->dashboard()['place']);

        // The member's own place wins.
        $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 36.16, 'lng' => -86.78]]));
        $this->assertSame('own', $this->dashboard()['place']['source']);

        // An empty default town clears it.
        $this->send($this->request('DELETE', '/api/homecoming/place', ['authenticatedAs' => 2]));
        $this->send($this->request('POST', '/api/settings', ['authenticatedAs' => 1, 'json' => ['ernestdefoe-homecoming.default_town' => '']]));
        $this->assertNull($this->dashboard()['place']);
    }

    #[Test]
    public function the_town_lookup_runs_once_per_area(): void
    {
        $this->fakePlaces();
        foreach ([[36.162664, -86.781602], [36.1641, -86.7849], [36.158, -86.776]] as [$lat, $lng]) {
            $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => $lat, 'lng' => $lng]]));
        }
        // All three round to the same 0.01° cell: one lookup, the rest from the cache.
        $this->assertSame([[36.16, -86.78]], FakePlaces::$reversed);
        $this->assertSame('Nashville, Tennessee, United States', $this->dashboard()['place']['label']);
    }

    #[Test]
    public function a_members_place_is_shown_to_them_alone(): void
    {
        $this->fakePlaces();
        $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 36.162664, 'lng' => -86.781602]]));
        $this->assertSame('Nashville, Tennessee, United States', $this->dashboard(2)['place']['label']);

        // Another member (here the admin) never gets it: not in their own dashboard, not in the
        // user API, not in a list of users. Neither the town nor the point is in any response.
        $this->assertNull($this->dashboard(1)['place']);
        foreach (['/api/users/2', '/api/users', '/api/users/2?include=groups'] as $url) {
            $body = (string) $this->send($this->request('GET', $url, ['authenticatedAs' => 1]))->getBody();
            $this->assertStringNotContainsString('Nashville', $body, $url);
            $this->assertStringNotContainsString('36.16', $body, $url);
            $this->assertStringNotContainsString('-86.78', $body, $url);
        }
        // The member's own user payload doesn't carry it either: only /api/homecoming does.
        $own = (string) $this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 2]))->getBody();
        $this->assertStringNotContainsString('Nashville', $own);
        // Guests can't reach any of it.
        $this->assertSame(401, $this->send($this->request('GET', '/api/homecoming'))->getStatusCode());
    }

    #[Test]
    public function gdpr_exports_the_place_and_erases_it_in_both_modes(): void
    {
        $this->extension('flarum-gdpr', 'ernestdefoe-homecoming');
        $this->fakePlaces();
        $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 36.16, 'lng' => -86.78]]));

        $c = $this->app()->getContainer();
        $this->assertArrayHasKey(\ErnestDefoe\Homecoming\Gdpr\HomecomingPlace::class, $c->make(\Flarum\Gdpr\DataProcessor::class)->types());

        $type = fn () => $c->make(\ErnestDefoe\Homecoming\Gdpr\HomecomingPlace::class, ['user' => User::findOrFail(2), 'erasureRequest' => null]);
        $export = $type()->export();
        $this->assertSame('homecoming/place.json', array_key_first($export[0]));
        $this->assertStringContainsString('Nashville, Tennessee, United States', $export[0]['homecoming/place.json']);

        // Anonymizing keeps the user row, so the foreign key never cleans up: the type must.
        $type()->anonymize();
        $this->assertSame(0, $this->database()->table('homecoming_places')->where('user_id', 2)->count());
        $this->assertNull($type()->export());

        $this->send($this->request('POST', '/api/homecoming/locate', ['authenticatedAs' => 2, 'json' => ['lat' => 36.16, 'lng' => -86.78]]));
        $type()->delete();
        $this->assertSame(0, $this->database()->table('homecoming_places')->where('user_id', 2)->count());
    }
}
