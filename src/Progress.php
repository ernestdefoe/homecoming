<?php

namespace ErnestDefoe\Homecoming;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Points and the way to the next rank, from the first of Renown, Ladder and
 * FoF Gamification that is enabled. None: null, and the bar is not drawn.
 *
 * Each answer is a range: the member has `points`, their rank began at `min`
 * and the next one starts at `max` (null at the top). Names that a language
 * pack or the admin sets (Renown's ranks) are left to the client.
 */
class Progress
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function for(User $user): ?array
    {
        if ($this->extensions->isEnabled('ernestdefoe-renown') && class_exists(\ErnestDefoe\Renown\Curve::class)) {
            return $this->renown($user);
        }
        if ($this->extensions->isEnabled('ernestdefoe-ladder') && class_exists(\Ernestdefoe\Ladder\Ladder::class)) {
            return $this->ladder($user);
        }
        if ($this->extensions->isEnabled('fof-gamification')) {
            return $this->gamification($user);
        }

        return null;
    }

    /**
     * Renown: 20 ranks of five levels. The bar runs from the first level of
     * the member's rank to the first of the next (columns on the user row).
     *
     * @return array<string, mixed>
     */
    private function renown(User $user): array
    {
        $curve = \ErnestDefoe\Renown\Curve::class;
        $level = max(1, (int) $user->getAttribute('renown_level'));
        $rank = $curve::rank($level);
        $grades = $curve::GRADES;
        $next = $rank * $grades + 1;

        return [
            'source' => 'renown',
            'points' => (int) $user->getAttribute('renown_xp'),
            'level' => $level,
            'rank' => $rank,
            'nextRank' => $next <= $curve::MAX_LEVEL ? $rank + 1 : null,
            'min' => $curve::xpFor(($rank - 1) * $grades + 1),
            'max' => $next <= $curve::MAX_LEVEL ? $curve::xpFor($next) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function ladder(User $user): ?array
    {
        $standing = resolve(\Ernestdefoe\Ladder\Ladder::class)->standing($user, $user);
        if (! is_array($standing)) {
            return null;
        }

        return [
            'source' => 'ladder',
            'metric' => $standing['metric'] ?? null,
            'points' => (int) ($standing['score'] ?? 0),
            'name' => $standing['current']['name'] ?? null,
            'color' => $standing['current']['color'] ?? null,
            'nextName' => $standing['next']['name'] ?? null,
            'min' => max(0, (int) ($standing['current']['min'] ?? 0)),
            'max' => isset($standing['next']['min']) ? (int) $standing['next']['min'] : null,
        ];
    }

    /**
     * FoF Gamification: points are `users.votes`; ranks are thresholds. The
     * rank list is the same for everyone, so it is kept ten minutes.
     *
     * @return array<string, mixed>
     */
    private function gamification(User $user): array
    {
        $points = (int) $user->getAttribute('votes');
        /** @var list<array{points: int, name: string, color: string}> $ranks */
        $ranks = $this->cache->remember('homecoming.fofranks', 600, fn () => $this->db->table('ranks')
            ->orderBy('points')
            ->get(['points', 'name', 'color'])
            ->map(fn ($r) => ['points' => (int) $r->points, 'name' => (string) $r->name, 'color' => (string) $r->color])
            ->all());

        $current = null;
        $next = null;
        foreach ($ranks as $rank) {
            if ($rank['points'] <= $points) {
                $current = $rank;
            } elseif ($next === null) {
                $next = $rank;
            }
        }

        return [
            'source' => 'gamification',
            'points' => $points,
            'name' => $current['name'] ?? null,
            'color' => $current['color'] ?? null,
            'nextName' => $next['name'] ?? null,
            'min' => max(0, $current['points'] ?? 0),
            'max' => $next['points'] ?? null,
        ];
    }
}
