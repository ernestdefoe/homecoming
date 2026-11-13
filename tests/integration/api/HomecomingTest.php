<?php

namespace ErnestDefoe\Homecoming\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Homecoming\Weather\FetchForecast;
use ErnestDefoe\Homecoming\Weather\Forecasts;
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
}
