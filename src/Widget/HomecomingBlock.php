<?php

namespace ErnestDefoe\Homecoming\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A Homecoming piece as a Page Builder block.
 *
 * resolve() hands Page Builder nothing: the client asks /api/homecoming like
 * every other host, so the numbers have one source and one set of rules.
 * Registered from extend.php only when Page Builder is installed.
 */
abstract class HomecomingBlock implements BlockInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** "welcome_strip", "stats_card", "compact_card" */
    abstract protected function piece(): string;

    public function type(): string
    {
        return 'homecoming-'.str_replace('_', '-', $this->piece());
    }

    public function name(): string
    {
        return $this->translator->trans('ernestdefoe-homecoming.lib.'.$this->piece());
    }

    public function category(): string
    {
        return 'forum';
    }

    public function defaultSettings(): array
    {
        return [];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
