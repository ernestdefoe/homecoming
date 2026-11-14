<?php

namespace ErnestDefoe\Homecoming;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Per-minute counters for anything that reaches a third party. One fixed key
 * per counter (a file cache never collects a file per request or per minute).
 */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    /** Count one use; false once `$limit` were used this minute. */
    public function take(string $key, int $limit): bool
    {
        $minute = intdiv(time(), 60);
        $count = $this->cache->get($key);
        if (! is_array($count) || ($count['m'] ?? null) !== $minute) {
            $count = ['m' => $minute, 'n' => 0];
        }
        if ($count['n'] >= $limit) {
            return false;
        }
        $count['n']++;
        $this->cache->put($key, $count, 120);

        return true;
    }
}
