<?php

namespace ErnestDefoe\Homecoming;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\DiscussionRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's own numbers: unread discussions, the four stats the admin chose
 * and their rank progress.
 *
 * Kept per member for two minutes, and dropped when they read or post. The
 * key is the member's id, so a file cache holds one entry per active member
 * (never one per visit). "Mark all as read" changes the stamp stored with it,
 * which makes the next ask rebuild without an event.
 */
class Dashboard
{
    public const STATS = ['discussions', 'posts', 'likes', 'best_answers', 'collections', 'badges', 'points', 'level', 'joined'];

    /** Shown when a chosen stat needs an extension that isn't enabled. */
    private const FALLBACK = ['discussions', 'posts', 'joined', 'likes', 'points', 'best_answers', 'badges', 'collections', 'level'];

    /** Unread counts stop here; the strip shows "99+". */
    public const UNREAD_CAP = 100;

    private const TTL = 120;

    public function __construct(
        private readonly Cache $cache,
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
        private readonly DiscussionRepository $discussions,
        private readonly Progress $progress,
    ) {
    }

    /** @return array{unread: int, stats: list<array{key: string, value: int|string|null}>, progress: ?array<string, mixed>} */
    public function for(User $actor): array
    {
        $key = 'homecoming.u.'.$actor->id;
        $stamp = $actor->marked_all_as_read_at?->getTimestamp() ?? 0;

        $hit = $this->cache->get($key);
        if (is_array($hit) && ($hit['stamp'] ?? null) === $stamp && is_array($hit['data'] ?? null)) {
            return $hit['data'];
        }

        $progress = $this->progress->for($actor);
        $data = [
            'unread' => $this->unread($actor),
            'stats' => $this->stats($actor, $progress),
            'progress' => $progress,
        ];

        $this->cache->put($key, ['stamp' => $stamp, 'data' => $data], self::TTL);

        return $data;
    }

    public function forget(int $userId): void
    {
        $this->cache->forget('homecoming.u.'.$userId);
    }

    /**
     * Core's "unread" rule (not read to the last post, newer than "mark all as
     * read"), limited to the last N days so a long-time member isn't told about
     * every discussion from years before they joined. Only what the member may
     * see, without private discussions or ones they ignore, like the list the
     * link opens.
     */
    private function unread(User $actor): int
    {
        $days = max(1, min(365, (int) $this->settings->get('ernestdefoe-homecoming.unread_days')));
        $since = Carbon::now()->subDays($days);
        if ($actor->marked_all_as_read_at && $actor->marked_all_as_read_at->greaterThan($since)) {
            $since = $actor->marked_all_as_read_at;
        }

        $query = Discussion::query()
            ->whereVisibleTo($actor)
            ->whereNull('discussions.hidden_at')
            ->where('discussions.is_private', false)
            ->where('discussions.last_posted_at', '>', $since)
            ->whereNotIn('discussions.id', $this->discussions->getReadIdsQuery($actor));

        if ($this->extensions->isEnabled('flarum-subscriptions')) {
            $query->whereNotIn('discussions.id', fn ($ignored) => $ignored
                ->select('discussion_id')
                ->from('discussion_user')
                ->where('user_id', $actor->id)
                ->where('subscription', 'ignore'));
        }

        return $this->db->query()
            ->fromSub($query->select('discussions.id')->limit(self::UNREAD_CAP), 'homecoming_unread')
            ->count();
    }

    /**
     * @param array<string, mixed>|null $progress
     * @return list<array{key: string, value: int|string|null}>
     */
    private function stats(User $actor, ?array $progress): array
    {
        $chosen = [];
        foreach ([1, 2, 3, 4] as $i) {
            $stat = (string) $this->settings->get('ernestdefoe-homecoming.stat'.$i);
            if (in_array($stat, self::STATS, true) && ! in_array($stat, $chosen, true) && $this->available($stat, $progress)) {
                $chosen[] = $stat;
            }
        }
        foreach (self::FALLBACK as $stat) {
            if (count($chosen) >= 4) {
                break;
            }
            if (! in_array($stat, $chosen, true) && $this->available($stat, $progress)) {
                $chosen[] = $stat;
            }
        }

        return array_map(fn (string $stat) => ['key' => $stat, 'value' => $this->value($stat, $actor, $progress)], $chosen);
    }

    /** @param array<string, mixed>|null $progress */
    private function available(string $stat, ?array $progress): bool
    {
        return match ($stat) {
            'likes' => $this->extensions->isEnabled('flarum-likes'),
            'best_answers' => $this->extensions->isEnabled('ernestdefoe-answers') || $this->extensions->isEnabled('fof-best-answer'),
            'collections' => $this->extensions->isEnabled('ernestdefoe-curio'),
            'badges' => $this->extensions->isEnabled('fof-badges'),
            'points' => $progress !== null,
            'level' => ($progress['source'] ?? null) === 'renown',
            default => true,
        };
    }

    /** @param array<string, mixed>|null $progress */
    private function value(string $stat, User $actor, ?array $progress): int|string|null
    {
        $id = (int) $actor->id;

        return match ($stat) {
            'discussions' => (int) $actor->discussion_count,
            'posts' => (int) $actor->comment_count,
            'joined' => $actor->joined_at?->toIso8601String(),
            'points' => (int) ($progress['points'] ?? 0),
            'level' => (int) ($progress['level'] ?? 0),
            // Likes on the member's posts by other members.
            'likes' => $this->db->table('post_likes')
                ->join('posts', 'posts.id', '=', 'post_likes.post_id')
                ->where('posts.user_id', $id)
                ->where('post_likes.user_id', '!=', $id)
                ->count(),
            'best_answers' => $this->extensions->isEnabled('ernestdefoe-answers')
                ? (int) $actor->getAttribute('answers_accepted_count')
                : $this->db->table('discussions')
                    ->join('posts', 'posts.id', '=', 'discussions.best_answer_post_id')
                    ->where('posts.user_id', $id)
                    ->count(),
            'collections' => $this->db->table('curio_collections')->where('user_id', $id)->count(),
            'badges' => $this->db->table('fof_badge_user')->where('user_id', $id)->count(),
            default => null,
        };
    }
}
